# Correlation and tracing

Eagle Eye links every entry produced by one unit of work (an HTTP request, a queued job, or a console command) into a single trace, and links consecutive entries into a causal chain. No application code is required.

## Identifiers

Every `ActivityEntry` carries a `TraceContext` (`$entry->trace`):

| Field | Format | Meaning |
|---|---|---|
| `traceId` (`trace_id`) | 32 lowercase hex characters (W3C trace id) | Shared by every entry of the unit of work and by work it causes (queued jobs, outbound calls). |
| `correlationId` (`correlation_id`) | ULID, or the inbound `X-Correlation-Id` value | Stable business correlation id, echoed back on responses. |
| `causationId` (`causation_id`) | Entry ULID or `null` | The id of the entry recorded immediately before this one in the same scope. The first entry of a scope has no causation id (or the one inherited from the dispatcher). |
| `parentSpanId` (`parent_span_id`) | 16 hex characters or `null` | The span id from an adopted inbound `traceparent`. |

Because each entry points at the previous one, a trace can be rendered as an ordered tree (for example `http.request` ← `model.updated` ← `queue.queued` ← `queue.processed`).

## How the context is created

A scoped `TraceManager` holds one context per unit of work.

### HTTP requests

At the first use in a request, the context is built from the request headers:

- A valid W3C `traceparent` header (`00-<32 hex trace id>-<16 hex span id>-<flags>`, all-zero ids rejected) is adopted: its trace id becomes `trace_id` and its span id becomes `parent_span_id`.
- A valid `X-Correlation-Id` header (1–128 characters of `A-Z a-z 0-9 . _ : -`) is adopted as `correlation_id`; otherwise a new ULID is generated.
- Without a valid `traceparent`, a new random trace id is generated.

The HTTP capture middleware adds an `X-Correlation-Id` response header with the active correlation id to every response.

### Queued jobs

When a job is pushed, Eagle Eye adds an `eagleEyeTrace` key to the job payload (through `Queue::createPayloadUsing()`) containing the dispatching context and the current causation id. When the worker starts the job (`JobProcessing`), the context is reset and the payload's context is adopted after validation. Invalid or missing payload data starts a fresh trace. This works for every queue driver and does not depend on `capture.queue.enabled`.

### Console commands

A console process starts with a fresh trace and keeps it for its lifetime. Commands called from another command, and scheduled tasks executed in-process by `schedule:run`, therefore share the trace of that process; tasks started in the background run in their own process and get their own trace. Console processes do not read HTTP headers.

### Outbound HTTP

When outbound capture is enabled, a global Laravel HTTP client request middleware (`Http::globalRequestMiddleware`) adds `traceparent` (with the current trace id and a new span id) and `X-Correlation-Id` to every outgoing request, so a downstream service that also runs Eagle Eye (or any W3C-aware tracer) continues the same trace. Calls made while capture is suppressed (for example, Eagle Eye's own Loki calls) are not modified. Guzzle clients that do not use Laravel's HTTP client can be captured with the `eagle-eye.guzzle_middleware` tag; that middleware records calls but does not inject headers.

## Reading a trace

```php
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Facades\EagleEye;

$result = EagleEye::query()
    ->forTrace($traceId)
    ->orderByTime(SortOrder::Ascending)
    ->limit(100)
    ->get();
```

Follow `$result->nextCursor` for long traces. From the command line:

```bash
php artisan activity-log:trace 4bf92f3577b34da6a3ce929d0e0e4736
```

The command prints every page of the trace in causal order. The JSON API exposes the assembled tree at `GET {prefix}/activity-logs/traces/{traceId}` (see [API](api.md)).

To find the trace of a request a user reported, ask for the `X-Correlation-Id` response header and filter by correlation id, or read `trace_id` from any entry of that request.

## Sampling and traces

Sampling decisions are derived from the trace id, so entries of a trace that are sampled at the same rate are kept or dropped together, including entries recorded in queued jobs that inherited the trace. Per-route rates apply only to the `http.request` entry; use equal rates when complete traces matter. See [Configuration › Sampling](configuration.md#sampling).

## Long-running processes

`TraceManager` is bound with `scoped()`. Laravel flushes scoped instances between Octane requests and between queue jobs, so a worker never leaks one request's trace into the next. Custom daemons that process several units of work in one process should call `app(\Dotswan\EagleEye\Correlation\TraceManager::class)->reset()` between units, or run each unit inside a fresh container scope.

## Customising propagation

Bind your own `Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface` to change how contexts are extracted from inbound headers and injected into outbound requests (for example to read a vendor-specific header). See [Extending](extending.md).
