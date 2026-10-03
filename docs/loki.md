# Loki driver

The `loki` driver targets **Loki 3.x (schema v13, TSDB)** and is intended for
high-volume deployments. One rule governs its design: **Loki indexes only stream
labels.** High-cardinality values in labels damage a shared cluster, so the driver
enforces a label allowlist in code when the application boots.

See [configuration](configuration.md) for the full `drivers.loki` block and
[drivers](drivers.md) for how Loki compares with the SQL drivers.

## Data layout

| Tier | Contents | How it is queried |
|---|---|---|
| Stream labels (indexed, bounded) | `app`, `env`, `channel`, `severity`; optionally `service` / `tenant` as static labels | `{app="my-app", env="production", channel="model"}` |
| Structured metadata (high-cardinality, filterable) | `id`, `trace_id`, `correlation_id`, `causation_id`, `actor_type`, `actor_id`, `subject_type`, `subject_id`, `action`, `route`, `method`, `status_code`, `duration_ms`, `tags` | `\| actor_id="8821"` |
| Line body (JSON) | the full canonical entry, including `title`, `summary`, `payload`, and `secrets` | parsed back into an `ActivityEntry` by the driver |

Rules:

- Labels come from `labels.static` (default `['app' => env('APP_NAME', 'laravel')]`)
  and `labels.dynamic` (default `['env', 'channel', 'severity']`). Only `app`, `env`,
  `channel`, `severity`, `service`, and `tenant` are allowed. Severity filters require
  the `severity` dynamic label.
- When the application boots, the label allowlist is validated and an
  `Dotswan\EagleEye\Exceptions\InvalidLabelException` is thrown for any disallowed
  label or label/metadata key collision. A configured `read.selector` is validated at
  the same time. Misconfiguration fails at boot, not at query time.
- `subject_type` is the subject's resolved type (for example `campaign_request`). The
  model class is stored in the body as `subject_class`; querying by a fully qualified
  class name uses a line filter on that field.
- `tags` is sent as comma-joined metadata and kept as a JSON array in the body. Tag
  queries extract the body array positions (`tags[0]` … `tags[49]`) and compare whole
  elements, never substrings.
- Set `LOKI_STRUCTURED_METADATA=false` (`read.structured_metadata`) for clusters
  without structured metadata. Reads then extract body fields with parameterized
  `| json ee_<field>="<field>"` expressions.

## Write paths

`write.path` (env `EAGLE_EYE_LOKI_WRITE`) selects how entries leave the application:

| `write.path` | Behaviour | Best for |
|---|---|---|
| `stdout` (default) | one canonical JSON line per entry on `write.stream` (default `php://stdout`), starting with `{"eagle_eye":true,` | clusters with an existing Alloy, Promtail, Fluent Bit, or Docker log collector |
| `push` | batched `POST {write.endpoint}/loki/api/v1/push`, gzip by default, optional `X-Scope-OrgID` (`LOKI_TENANT_ID`) and basic/bearer auth (`LOKI_AUTH`, `LOKI_USER`, `LOKI_TOKEN`) | direct shipping, Grafana Cloud, non-container hosts |
| `spool` | NDJSON on disk at `write.spool` (`LOKI_SPOOL_PATH`), shipped later by `activity-log:loki-ship` | outages, strict network isolation |

All three paths carry the same canonical entry. In stdout lines the body fields are
authoritative; label values such as `app` are appended only when the body does not
already contain that key. Push sends a Loki values tuple with structured metadata.

### Push retries and the spool

When a push fails (non-2xx response or transport error), the push writer retries with
the delays in `write.retry.backoff_ms` (default `[200, 1000, 5000]`; at most five
delays, each capped at 10 s). After the last attempt the batch is appended to the
spool when `write.spool` is set (it is by default); otherwise a
`LokiWriteException` reaches the transport, which applies `transport.on_failure`.

`php artisan activity-log:loki-ship [--chunk=500]` re-pushes spooled lines. Lines that
fail to ship stay in the spool for the next run. The Loki spool is capped at 256 MiB;
when it is full, the write fails and the outer transport failure policy applies. A
local spool does not protect against loss of the node.

### Timeouts and response limits

`write.timeout` (default 5 s) and `read.timeout` (default 15 s) are whole-operation
deadlines covering connection, headers, body transfer, retries, and backoff. Response
bodies are streamed with a 2 MiB decoded ceiling (smaller for readiness and push error
bodies). Compressed responses are rejected because their decoded size cannot be
bounded in advance. HTTP calls require Laravel's default cURL-backed client; a custom
or unknown Guzzle handler is rejected before any I/O.

## Collector mode: stdout write, Loki API read

Write to stdout and give the driver the stream selector your collector assigns:

```dotenv
EAGLE_EYE_DRIVER=loki
EAGLE_EYE_LOKI_WRITE=stdout
EAGLE_EYE_LOKI_READ_SELECTOR='{namespace="my-app-prod",container="app"}'
LOKI_QUERY_URL=https://loki.example.com
LOKI_READ_AUTH=basic
LOKI_READ_USER=eagle-eye
LOKI_READ_TOKEN=secret
# LOKI_READ_TENANT_ID=tenant-id
```

`read.selector` is an operator-controlled LogQL stream selector, never request input.
It must contain at least one matcher and cannot contain a pipeline. Without a selector
the driver queries its own labels. With a selector the driver uses it verbatim, adds
the `{"eagle_eye":true,` marker as the first line filter, and extracts required body
fields into collision-safe `ee_`-prefixed names. Collector mode does not require
structured metadata and ignores `read.structured_metadata`.

Collector requirements:

- Store the application JSON line verbatim as the Loki log line. Unwrap Docker/CRI
  envelopes; do not prefix or re-serialize it.
- Reassemble split lines. Docker and containerd may split lines around 16 KiB, while an
  entry may reach 128 KiB.
- Set Loki `max_line_size` to at least 128 KiB plus collector overhead. Loki's default
  of 256 KB is sufficient.
- Forward `php://stdout` to container stdout. With PHP-FPM, `catch_workers_output=yes`
  can add prefixes and truncate at `log_limit`; FrankenPHP, Octane, CLI, and queue
  workers normally expose stdout directly. `write.stream` may be `php://stderr` when
  that stream is collected verbatim.

Concurrent writers can interleave pipe writes larger than `PIPE_BUF`; invalid lines
are skipped on read. At-least-once collectors can resend an entry with a new ingestion
timestamp; reads deduplicate by entry `id`. Use a narrow selector (workload or
container labels), keep query windows small, and restrict access to container logs and
the Loki tenant: activity data is visible there after redaction and encryption.

Ordering, cursors, and `between()` use the Loki line timestamp. In collector mode this
is usually ingestion time, so `logged_at` values can appear slightly out of order.
Aggregation is available for `channel`, `severity`, `actor`, and the structured
metadata keys, but not for collector labels such as `app`, `env`, `service`, or
`tenant`.

`forget()` is not supported with `write.path=stdout`: it returns a failed erasure
result because a line already handed to a collector cannot be retracted. Enforce
retention in Loki.

In stdout write mode, Artisan commands that are not excluded also print Eagle Eye JSON
lines to stdout when they finish. `capture.console.except` controls this; its default
already excludes read-only commands such as `route:list`, `config:show`, `*:list`,
`help`, `about`, `model:show`, and `db:show`, so machine-readable output like
`php artisan route:list --json` stays parseable.

## Optional Alloy configuration

`php artisan activity-log:loki-config [--retention-days=N]` prints an Alloy
`loki.process` stage for the stdout path and a matching `limits_config` retention
fragment. It is optional; collector mode does not require this exact pipeline. With
structured metadata enabled the output looks like this:

```river
loki.process "activity_log" {
  forward_to = [loki.write.default.receiver]

  stage.json {
    expressions = { app = "app", env = "env", channel = "channel", severity = "severity", logged_at = "logged_at", id = "id", trace_id = "trace_id", ... }
  }
  // ONLY these become labels — never actor_id / subject_id / trace_id
  stage.labels { values = { app = "", env = "", channel = "", severity = "" } }

  stage.structured_metadata {
    values = { id = "", trace_id = "", correlation_id = "", causation_id = "",
               actor_type = "", actor_id = "", subject_type = "", subject_id = "",
               action = "", route = "", method = "", status_code = "", duration_ms = "",
               tags = "" }
  }

  stage.timestamp { source = "logged_at", format = "RFC3339Nano" }
}
```

The command exits with an error when `retention.tags` is configured, because Loki
stream retention cannot select on structured metadata.

## LogQL translation

| `ActivityQuery` call | LogQL fragment (structured-metadata mode) |
|---|---|
| `channels(ActivityChannel::Model, ActivityChannel::Http)` | `channel=~"model\|http"` in the selector |
| `severities(ActivitySeverity::Error)` | `severity=~"error"` in the selector |
| `search('payment declined')` | `\|~ "(?i)payment declined"` line filter |
| `forActor('user', 8821)` | `\| actor_type="user" \| actor_id="8821"` |
| `forSubject('campaign', 77213)` | `\| subject_type="campaign" \| subject_id="77213"` |
| `forSubject(App\Models\Order::class, 9)` | `\|= "\"subject_class\":\"App\\\\Models\\\\Order\""` line filter plus `\| subject_id="9"` |
| `forTrace($id)` | `\| trace_id="…"` |
| `actions('model.updated')` | `\| action=~"^(model\\.updated)$"` |
| `withAnyTag(['pci', 'gdpr'])` | `\| json ee_tag_0="tags[0]", … \| (ee_tag_0=~"pci\|gdpr" or …)` |
| `whereStatus('>=', 500)` | `\| status_code >= 500` |
| `between($from, $to)` | `start` / `end` query parameters; without a range the last `read.default_range_days` (default 30) days |
| `orderByTime('asc')` | `direction=forward` (default `backward`) |

Line filters (collector marker, search, class) come first, followed by metadata
filters, or by parameterized JSON extraction and field filters in JSON and collector
modes.

Aggregates use instant metric queries such as
`sum by (channel) (count_over_time({…}[Ns]))`. Grouping works for stream labels and
structured metadata keys. Rankings (`aggregateTop()`) request `topk(1001, …)`, sort by
count and then by key, and return the requested 1–100 winners. If 1,001 groups come
back and the requested cutoff is tied, the driver throws a `LengthException` instead of
returning an arbitrary winner. Ordinary aggregates reject more than 1,000 groups.

Series (`aggregateSeries()`) use `query_range` with `step` equal to the bucket width.
Loki evaluates `count_over_time(x[step])` at `ts` over `(ts-step, ts]`, so the driver
queries from `aligned_from + step` and maps each point `ts` to bucket
`(ts - aligned_from) / step - 1`, which covers `[bucket, bucket + step)`.

## Pagination, gaps, duplicates

Loki's range `end` is exclusive and several lines can share a nanosecond. The driver
therefore:

1. sorts client-side by `(Loki line timestamp, id)` for a deterministic total order,
2. re-reads the boundary timestamp and excludes IDs it has already returned, and
3. drops anything at or before the cursor's `(timestamp, id)` tuple.

`Cursor` encodes `(timestamp, last_id, direction)` opaquely. If a page cannot advance
past a repeated timestamp, or more than 1,000 entries share one boundary timestamp,
the driver throws a `RuntimeException` reporting incomplete results instead of looping
or silently omitting rows. A single search scans at most 1,000 backend pages. Use the
SQL `index` accelerator (see [drivers](drivers.md)) for complete high-cardinality
histories.

If a full Loki page contains only undecodable lines, the driver advances past that
page's timestamp edge to guarantee progress. A valid line at exactly that nanosecond
can then be skipped. Keep the selector narrow and collector output valid.

## Limitations

| Limitation | Mitigation |
|---|---|
| Every query is time-bounded | default window of `read.default_range_days` (30); pass `between()` for other ranges |
| No exact count | `count()` uses `count_over_time` and can be inflated by duplicate ingestion; `QueryResult::$exactCount` is `false` and `Capability::ExactCount` is not advertised |
| Time ordering only | stable `(Loki line timestamp, id)` cursors; in collector mode this is ingestion time |
| No live tail | `Capability::LiveTail` is not supported |
| `prune()` cannot reach the cluster | returns `0`; configure retention in Loki `limits_config` (see `activity-log:loki-config`) |
| Deletion is asynchronous | `forget()` submits compactor delete requests for the subject and for the matching actor and returns `accepted_pending` with both request IDs; monitor them until `processed` and allow for later compaction |
| Stdout erasure is unavailable | `forget()` returns a failed result; enforce retention in Loki |

Supported capabilities: `full_text_search`, `aggregation`, `ranked_aggregation`, and
`series_aggregation`.

Deletion requests are sent to `POST /loki/api/v1/delete` with RFC 3339 bounds. A
request counts as accepted only when Loki answers `204` and the request then appears in
`GET /loki/api/v1/delete`. A request for the same query and range that is still in the
`received` state is reused; processed requests are never reused for newly ingested
data.
Enable the compactor deletion API on the cluster. See [privacy](privacy.md) for the
erasure flow.

## Retention example

```yaml
limits_config:
  retention_period: 720h
  retention_stream:
    - selector: '{channel="auth"}'
      priority: 1
      period: 8760h
```

## Running the contract suite against Loki

The driver contract suite runs against a real Loki container in structured-metadata,
JSON, and collector modes. `EAGLE_EYE_LOKI_TEST_URL` and `EAGLE_EYE_LOKI_MODE` are
test-only variables:

```bash
docker run -d --name eagle-eye-loki -p 3100:3100 \
  -v "$PWD/tests/Support/loki-ci.yaml:/etc/loki/local-config.yaml:ro" \
  grafana/loki:3.4.2
EAGLE_EYE_LOKI_TEST_URL=http://127.0.0.1:3100 vendor/bin/pest tests/Feature/LokiContractTest.php
EAGLE_EYE_LOKI_TEST_URL=http://127.0.0.1:3100 EAGLE_EYE_LOKI_MODE=json vendor/bin/pest tests/Feature/LokiContractTest.php
EAGLE_EYE_LOKI_TEST_URL=http://127.0.0.1:3100 EAGLE_EYE_LOKI_MODE=collector vendor/bin/pest tests/Feature/LokiContractTest.php
```
