# Performance

Eagle Eye is designed to stay off the request's critical path and to use bounded memory in long-running processes. This page describes what each unit of work costs, what is deferred, and how to tune the package for hot paths.

## Lifecycle of an entry

1. **Capture.** A listener or the HTTP middleware builds an `ActivityDraft`. Capture code never lazy-loads relations or reads response streams.
2. **Gate.** Before any payload work, `Recorder` checks the kill switch (`enabled`), the allowed `environments`, capture suppression, and the cheap filters (channel, severity, actor, method, URL). Rejected drafts cost a few array lookups.
3. **Pipeline.** Accepted drafts run through the configured pipes: actor/context/trace resolution, the full filter chain, sampling, request-part projection, enrichers, normalisation, redaction, encryption, subject metadata, tags, title rendering, budget enforcement, and optional integrity sealing. Filtering and sampling run before the expensive stages.
4. **Buffer.** The immutable `ActivityEntry` is appended to a per-scope buffer (at most `transport.buffer` entries, hard ceiling 100 entries or 2 MiB).
5. **Flush.** The buffer is delivered in batches of at most 100 entries or 1 MiB through the configured transport. No I/O happens on `record()` unless the buffer fills.

## When delivery happens

The buffer is flushed:

- after the HTTP response has been sent (`terminating` callback),
- after each queued job is processed, fails, or throws (`JobProcessed`, `JobFailed`, `JobExceptionOccurred`) on non-`sync` connections, on each worker loop iteration (`Looping`), and when a worker stops,
- after each Octane request (`Laravel\Octane\Events\RequestTerminated`),
- at PHP shutdown,
- immediately when the buffer reaches its entry or byte capacity.

On the `sync` queue connection, job events do not flush; the entries stay in the surrounding request's buffer and are delivered with it.

### Transactions

When a flush is triggered while the erasure connection (`privacy.erasure_connection`, default connection by default) has an open transaction, delivery is registered as an after-commit callback instead of running inside the transaction. Rolled-back work therefore never blocks on, or is partially persisted by, activity delivery. Eloquent model events raised inside a transaction are likewise recorded only after the transaction commits and are discarded on rollback.

If a transaction is leaked and still open at termination, delivery is attempted once, fails, and the batch goes to the spool (reported once per process and shown by `activity-log:doctor`).

## Cost per unit of work

| Item | Cost |
|---|---|
| HTTP request | One `http.request` entry, built after the response is sent. Request parts are read only for configured `capture.http.parts`; bodies only for `body_routes`; response bodies only for JSON responses that qualify (`errors_only` by default), never for streamed or binary responses. |
| Eloquent write | One entry per configured model event with changed attributes only. The wildcard `eloquent.*` listener returns immediately for events that are not configured. |
| SQL query (summary on) | One `QueryExecuted` listener call: fingerprint lookup in a bounded memo (keyed by `xxh3`, 50 entries, statements over 4 KiB are fingerprinted without memoising) and counter updates. No entry is created per query. |
| SQL query (`capture.query.enabled`) | Additionally one `query.slow` entry per query at or above `slow_threshold_ms`. |
| Outbound HTTP | One entry per response/failure. Error bodies are read only for JSON responses with status ≥ 400, at most `error_body_max_bytes + 1` bytes, only from seekable streams, and the stream position is restored. Success bodies are never read. |
| Mail / notification | One entry per sent/failed message. Bodies are never rendered or stored. |
| Exception | One entry per distinct exception per scope (deduplicated with up to 256 fingerprints). |
| Redaction | Key-pattern checks per payload key; email detection only on string values containing `@`. |
| Encryption | Only for values in `encrypt` mode: one Laravel encryption and one HMAC blind index per value. |
| Integrity (opt-in) | One row-locked ledger write per sealed entry on `integrity.connection`. |

## Memory bounds

All per-process state is bounded and reset per scope (container `scoped` bindings are flushed between requests and jobs by Laravel, Octane, and queue workers):

| State | Bound |
|---|---|
| Activity buffer | 100 entries / 2 MiB |
| Query summary | 8 nested frames, 50 fingerprints, 50 memoised statements (≤ 4 KiB each), 5 slow and 5 duplicate entries per frame |
| Exception deduplication | 256 fingerprints |
| Payload | `payload.*` budgets, hard 64 KiB payload / 128 KiB envelope |
| HTTP bodies | 16 KiB request and response |
| Loki responses | 2 MiB decoded, streamed with a whole-operation deadline |
| `activity-log:export` | Streams pages; memory does not grow with the result set |
| `EagleEye::fake()` / `memory` driver | 1,000 entries by default (max 100,000), oldest evicted first |

## Transport strategies

| `transport.driver` | Behaviour | Use when |
|---|---|---|
| `sync` (default) | Writes batches to the driver during the flush, after the response is sent. | The driver is local or fast (database on the same network, Loki `stdout`). |
| `queue` | Dispatches one `PersistActivityBatchJob` per batch (3 tries, backoff 1/5/15 s; failed batches are spooled). | The driver is remote or slow, or flushes run in latency-sensitive workers. Use a dedicated queue (`transport.queue.queue`). |
| `stream` | Writes JSON lines to `transport.stream.target`. | You forward logs with an external shipper and do not need Eagle Eye to read them back. |

`transport.on_failure` decides what happens when delivery throws: `spool` (default) appends to `transport.spool.path` (quota `transport.spool.max_bytes`), `ignore` drops the batch, `throw` raises. Replay spooled entries with `php artisan activity-log:ship`; the Loki push writer has its own spool drained by `php artisan activity-log:loki-ship`.

For Loki the cheapest write path is `drivers.loki.write.path = stdout` with a log collector (Grafana Alloy, Promtail, Vector) shipping container output: the request only writes one line per entry to stdout. See [Loki](loki.md).

## Tuning hot paths

- **Exclude noisy URLs and methods** with `filters.url.except` / `filters.method.except` (health checks, metrics, polling endpoints). These are rejected in the cheap gate.
- **Sample high-volume traffic** with `sampling.channels` (for example `['http' => 0.1]`) or `sampling.routes`. Sampling is per trace, so kept traces stay complete.
- **Drop whole channels** you do not need with `capture.<channel>.enabled = false` (or the matching `EAGLE_EYE_CAPTURE_*` variable). A disabled adapter registers no listeners.
- **Turn off the SQL summary** (`EAGLE_EYE_CAPTURE_QUERY_SUMMARY=false`) in query-heavy batch commands if even its small per-query cost matters.
- **Keep `capture.query.enabled` off** in production unless you are investigating; slow-query entries are additional writes.
- **Exclude chatty models** with `filters.models.except` or `#[DoNotLog]`, and limit `capture.model.events`.
- **Keep body capture narrow.** `body_routes` should list only the routes you need to debug.
- **Exclude chatty outbound hosts** with `capture.outbound_http.except_hosts`.
- **Raise `filters.severity.min`** to `info` or `notice` to drop debug-level custom entries.
- **Wrap bulk operations** in `EagleEye::withoutLogging(fn () => ...)` (or `eagle_eye_silent()`) for imports and seeders.

## Recommended production settings

```dotenv
EAGLE_EYE_SECURITY_KEY=base64:...        # dedicated, stable, identical on all processes
EAGLE_EYE_DRIVER=database                # or loki / multi
EAGLE_EYE_CAPTURE_QUERY=false            # per-query entries off
EAGLE_EYE_CAPTURE_QUERY_SUMMARY=true     # cheap aggregate on
```

```php
// config/eagle-eye.php
'transport' => [
    'driver'     => 'queue',              // for remote drivers; 'sync' is fine for a local database
    'buffer'     => 100,
    'on_failure' => 'spool',
    'queue'      => ['connection' => null, 'queue' => 'activity'],
    // ...
],
'sampling' => ['rate' => 1.0, 'channels' => [], 'routes' => []],
'privacy'  => ['reveal_audit_driver' => 'database', /* ... */],
```

Schedule maintenance:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('activity-log:prune')->daily();
Schedule::command('activity-log:ship')->everyFiveMinutes()->withoutOverlapping();
```

With the Loki push writer, also schedule `activity-log:loki-ship`. Monitor spool size, rejected batches, queue depth, driver health (`activity-log:doctor`), and Loki 429 responses.

## Octane and long-running workers

- All per-unit-of-work state (buffer, trace context, query summary frames, exception deduplication, capture suppression, filters) is bound with `scoped()`, so Octane and queue workers start each request or job clean without package-specific listeners.
- The buffer is flushed on Octane `RequestTerminated` and on queue job completion, so entries are not held across units of work.
- Queue jobs receive the dispatching trace through the job payload and adopt it in `JobProcessing`; no global job middleware is required.
- Long-running daemons that are not Laravel workers (custom `while (true)` loops) should call `app(\Dotswan\EagleEye\Transport\Flusher::class)->flush()` periodically, or rely on the buffer flushing itself every 100 entries.

## Verifying performance locally

The test suite contains resource probes, for example bounded memory across ten thousand worker scopes and a bounded overhead factor for the query listener:

```bash
php -d memory_limit=256M vendor/bin/pest --group=performance
```

Measure in your own staging environment before choosing retention, worker memory, target throughput, and queue thresholds.
