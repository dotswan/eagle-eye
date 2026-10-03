# Configuration reference

Eagle Eye works without a published configuration file: the package merges its defaults from `config/eagle-eye.php`. Publish the file when you need to change structural settings (filters, privacy rules, retention, pipeline):

```bash
php artisan vendor:publish --tag=eagle-eye-config
```

Environment-specific values (driver, endpoints, credentials, switches) are read from environment variables; everything else lives in `config/eagle-eye.php`. After a production configuration change, run `php artisan activity-log:doctor`.

Integer settings marked with a range are clamped to that range at runtime. Independently of configuration, the package enforces hard ceilings that cannot be raised (see [Hard limits](#hard-limits)).

- [Environment variables](#environment-variables)
- [Core](#core)
- [Drivers](#drivers)
- [Tags](#tags)
- [Pipeline](#pipeline)
- [Filters and overrides](#filters-and-overrides)
- [Privacy](#privacy)
- [Retention](#retention)
- [Integrity](#integrity)
- [Sampling](#sampling)
- [Transport](#transport)
- [API](#api)
- [Capture](#capture)
- [Payload](#payload)
- [Subjects](#subjects)
- [Hard limits](#hard-limits)

## Environment variables

Every environment variable read by `config/eagle-eye.php`:

| Variable | Config key | Default |
|---|---|---|
| `EAGLE_EYE_SECURITY_KEY` | `security_key` | none (required outside `local`/`testing`) |
| `EAGLE_EYE_ENABLED` | `enabled` | `true` |
| `EAGLE_EYE_DRIVER` | `default` | `database` |
| `EAGLE_EYE_MULTI_PRIMARY` | `drivers.multi.primary` | `loki` |
| `EAGLE_EYE_MULTI_ACCELERATOR` | `drivers.multi.accelerator` | `index` |
| `EAGLE_EYE_LOKI_WRITE` | `drivers.loki.write.path` | `stdout` |
| `LOKI_PUSH_URL` | `drivers.loki.write.endpoint` (and read fallback) | `http://127.0.0.1:3100` |
| `LOKI_TENANT_ID` | `drivers.loki.write.tenant` (and read fallback) | none |
| `LOKI_AUTH` | `drivers.loki.write.auth.type` (and read fallback) | `none` |
| `LOKI_USER` | `drivers.loki.write.auth.username` (and read fallback) | none |
| `LOKI_TOKEN` | `drivers.loki.write.auth.password` (and read fallback) | none |
| `LOKI_SPOOL_PATH` | `drivers.loki.write.spool` | `storage/logs/eagle-eye-loki.spool` |
| `LOKI_QUERY_URL` | `drivers.loki.read.endpoint` | `LOKI_PUSH_URL`, then `http://127.0.0.1:3100` |
| `LOKI_STRUCTURED_METADATA` | `drivers.loki.read.structured_metadata` | `true` |
| `EAGLE_EYE_LOKI_READ_SELECTOR` | `drivers.loki.read.selector` | none |
| `LOKI_READ_TENANT_ID` | `drivers.loki.read.tenant` | `LOKI_TENANT_ID` |
| `LOKI_READ_AUTH` | `drivers.loki.read.auth.type` | `LOKI_AUTH`, then `none` |
| `LOKI_READ_USER` | `drivers.loki.read.auth.username` | `LOKI_USER` |
| `LOKI_READ_TOKEN` | `drivers.loki.read.auth.password` | `LOKI_TOKEN` |
| `APP_NAME` | `drivers.loki.labels.static.app` | `laravel` |
| `EAGLE_EYE_API_ENABLED` | `api.enabled` | `false` |
| `EAGLE_EYE_API_PREFIX` | `api.prefix` | `api/v1` |
| `EAGLE_EYE_CAPTURE_HTTP` | `capture.http.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_HTTP_BODY_ROUTES` | `capture.http.body_routes` (comma-separated) | empty |
| `EAGLE_EYE_CAPTURE_MODEL` | `capture.model.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_AUTH` | `capture.auth.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_QUEUE` | `capture.queue.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_CONSOLE` | `capture.console.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_MAIL` | `capture.mail.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_NOTIFICATION` | `capture.notification.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_OUTBOUND_HTTP` | `capture.outbound_http.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_EXCEPTION` | `capture.exception.enabled` | `true` |
| `EAGLE_EYE_CAPTURE_QUERY` | `capture.query.enabled` (per-query `query.slow` events) | `false` |
| `EAGLE_EYE_CAPTURE_QUERY_SUMMARY` | `capture.query.summary` | `true` |
| `EAGLE_EYE_QUERY_SLOW_MS` | `capture.query.slow_threshold_ms` | `500` |

The `LOKI_*` names are deliberately generic. If another package in your application uses the same variable names for a different Loki, publish the configuration and rename them.

## Core

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `security_key` | `EAGLE_EYE_SECURITY_KEY` | string | `null` | Dedicated secret (at least 32 bytes) that authenticates erasure barriers, receipts, delivery attempts, and the integrity ledger. Must be identical on every web, queue, and scheduler process and must never be rotated with `APP_KEY`. In `local` and `testing` the application key is used when it is missing; in every other environment a missing or short key makes Eagle Eye fail to resolve. Generate one with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`. |
| `enabled` | `EAGLE_EYE_ENABLED` | bool | `true` | Global kill switch, read on every `record()` call, so it can be flipped at runtime (`config(['eagle-eye.enabled' => false])`). Disabled recording returns `null`. |
| `environments` | — | list of strings | `['production', 'staging', 'local', 'testing']` | Application environments allowed to record. `'*'` allows every environment. Evaluated once per request/job scope. |
| `default` | `EAGLE_EYE_DRIVER` | string | `database` | Name of the active driver: `database`, `loki`, `multi`, `index`, `null`, or a custom driver registered with `DriverManager::extend()`. |

## Drivers

`drivers.<name>` holds per-driver settings. See [Drivers](drivers.md) and [Loki](loki.md) for behaviour.

### `drivers.null`

| Key | Default | Description |
|---|---|---|
| `drivers.null.driver` | `null` | Discards every entry. Use only for deliberate discard. |

### `drivers.database`

| Key | Type | Default | Description |
|---|---|---|---|
| `drivers.database.driver` | string | `database` | Driver identifier. |
| `drivers.database.connection` | ?string | `null` | Database connection name; `null` uses the application default. |
| `drivers.database.table` | string | `activity_logs` | Table that stores entries. Rename the table in the published migration as well if you change it. |

### `drivers.index`

| Key | Type | Default | Description |
|---|---|---|---|
| `drivers.index.driver` | string | `index` | Header-only SQL projection used as the accelerator of the `multi` driver. |
| `drivers.index.connection` | ?string | `null` | Database connection name. |
| `drivers.index.table` | string | `activity_log_index` | Index table name. |

### `drivers.multi`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `drivers.multi.driver` | — | string | `multi` | Composite driver. |
| `drivers.multi.primary` | `EAGLE_EYE_MULTI_PRIMARY` | string | `loki` | Driver that stores full entries. Must implement the full driver contract. |
| `drivers.multi.accelerator` | `EAGLE_EYE_MULTI_ACCELERATOR` | ?string | `index` | Driver used for listings, counts, rankings, and traces. Empty disables it. |
| `drivers.multi.on_failure.primary` | — | string | `throw` | `throw`, `ignore`, or `fallback`. `fallback` treats entries as delivered when an accelerator that stores full bodies accepted them. |
| `drivers.multi.on_failure.accelerator` | — | string | `ignore` | `throw` or `ignore`. |

### `drivers.loki`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `drivers.loki.driver` | — | string | `loki` | Grafana Loki 3.x driver. |
| `drivers.loki.write.path` | `EAGLE_EYE_LOKI_WRITE` | string | `stdout` | `stdout` (one self-describing JSON line per entry for a log collector), `push` (HTTP push API), or `spool` (append to the local spool file only). |
| `drivers.loki.write.stream` | — | string | `php://stdout` | Stream used by the `stdout` writer. |
| `drivers.loki.write.endpoint` | `LOKI_PUSH_URL` | string | `http://127.0.0.1:3100` | Base URL for `push` and readiness checks. This host is never captured as outbound HTTP. |
| `drivers.loki.write.tenant` | `LOKI_TENANT_ID` | ?string | `null` | `X-Scope-OrgID` tenant header. |
| `drivers.loki.write.auth.type` | `LOKI_AUTH` | string | `none` | `none`, `basic`, or `bearer`. |
| `drivers.loki.write.auth.username` | `LOKI_USER` | ?string | `null` | Basic-auth username. |
| `drivers.loki.write.auth.password` | `LOKI_TOKEN` | ?string | `null` | Basic-auth password or bearer token. |
| `drivers.loki.write.gzip` | — | bool | `true` | Gzip push bodies. |
| `drivers.loki.write.retry.backoff_ms` | — | list of ints | `[200, 1000, 5000]` | Backoff before each push retry after a non-2xx response or network error. At most 5 retries, each capped at 10 s, all within the write timeout. |
| `drivers.loki.write.spool` | `LOKI_SPOOL_PATH` | ?string | `storage/logs/eagle-eye-loki.spool` | Local file for undeliverable push batches (fixed 256 MiB quota). Drain with `activity-log:loki-ship`. Empty disables the push fallback. |
| `drivers.loki.write.timeout` | — | int (seconds) | `5` | Whole-operation deadline per push, including retries. |
| `drivers.loki.read.endpoint` | `LOKI_QUERY_URL` | string | `LOKI_PUSH_URL` or `http://127.0.0.1:3100` | Query API base URL. Excluded from outbound capture. |
| `drivers.loki.read.timeout` | — | int (seconds) | `15` | Whole-operation deadline per query. |
| `drivers.loki.read.default_range_days` | — | int | `30` | Time range applied when a query has none (Loki requires bounded ranges). |
| `drivers.loki.read.structured_metadata` | `LOKI_STRUCTURED_METADATA` | bool | `true` | Filter on Loki structured metadata. Set `false` for Loki deployments without structured metadata (JSON extraction is used instead). Ignored when `selector` is set. |
| `drivers.loki.read.selector` | `EAGLE_EYE_LOKI_READ_SELECTOR` | ?string | `null` | Complete LogQL stream selector such as `{namespace="my-app-prod",stream="stdout"}`. Enables collector mode: entries are read from the JSON body written by the `stdout` writer. Validated at boot. |
| `drivers.loki.read.tenant` | `LOKI_READ_TENANT_ID` | ?string | write tenant | Read tenant. |
| `drivers.loki.read.auth.type` | `LOKI_READ_AUTH` | string | write auth type | Read auth type. When unset the write credentials are used. |
| `drivers.loki.read.auth.username` | `LOKI_READ_USER` | ?string | write username | Read username. |
| `drivers.loki.read.auth.password` | `LOKI_READ_TOKEN` | ?string | write password | Read password or token. |
| `drivers.loki.labels.static` | `APP_NAME` for `app` | map | `['app' => env('APP_NAME', 'laravel')]` | Constant stream labels. Validated at boot by the cardinality guard. |
| `drivers.loki.labels.dynamic` | — | list | `['env', 'channel', 'severity']` | Entry fields promoted to stream labels. Static and dynamic label names must be one of `app`, `env`, `channel`, `severity`, `service`, `tenant`; anything else fails at boot. Identifiers stay in structured metadata. |

## Tags

| Key | Type | Default | Description |
|---|---|---|---|
| `tags.static` | list of strings | `[]` | Tags added to every entry (for example `['service:billing']`). |
| `tags.max` | int (1–100) | `24` | Maximum tags per entry; extra tags are dropped. |
| `tags.automatic` | bool | `true` | Adds `channel:<channel>`, `route:<name>`, `status:4xx`/`status:5xx`, `slow`, and `module:<second namespace segment of the subject class>` (for `App\Models\Order` this is `module:models`). |
| `tags.slow_threshold_ms` | int (1–600,000) | `1000` | Request duration at or above which the `slow` tag is added. |

## Pipeline

| Key | Type | Default | Description |
|---|---|---|---|
| `pipeline` | list of class names | see below | Ordered pipes every draft runs through. Each pipe implements `ActivityPipeInterface`; returning `null` drops the draft. |

Default order:

```php
ResolveActor, ResolveContext, AttachCorrelation, ApplyFilters, SampleEntry,
ProjectRequestParts, RunEnrichers, NormalizePayload, RedactSensitive,
EncryptSensitive, ReadSubjectMetadata, AttachTags, ResolveTitle,
EnforceBudget, SealIntegrity
```

All classes live in `Dotswan\EagleEye\Pipeline\Pipes`. Insert custom pipes where needed, but keep `RedactSensitive` and `EncryptSensitive` before anything that persists or forwards data, and keep `RunEnrichers` (tagged enrichers, including the SQL query summary) before redaction. `activity-log:doctor` warns when a published pipeline omits `RunEnrichers`. See [Extending](extending.md).

## Filters and overrides

Pattern syntax for every list below: exact string, `*` wildcard, or a delimited regular expression such as `/^admin\/(users|roles)/`. See [Filtering](filtering.md).

| Key | Type | Default | Description |
|---|---|---|---|
| `filters.default_action` | `allow` \| `deny` | `allow` | Decision when no rule allows or denies. With `deny`, only drafts explicitly allowed by an `only` list or a custom rule are recorded. |
| `filters.url.only` / `.except` | list | `[]` / `['up', 'health', 'horizon/*', 'telescope/*', 'pulse/*', '_debugbar/*', 'livewire/*', 'livewire-*/livewire*.js', 'livewire-*/*.js.map']` | Matched against the full URL, the path, and the path without a leading slash. |
| `filters.method.only` / `.except` | list | `[]` / `['OPTIONS', 'HEAD']` | Upper-case HTTP methods. |
| `filters.channels.only` / `.except` | list | `[]` / `[]` | Channel values (`http`, `model`, `auth`, `queue`, `console`, `mail`, `notification`, `outbound_http`, `exception`, `log`, `schedule`, `query`, `custom`). |
| `filters.actors.except` | list | `[]` | Actor type (`user`), id (`42`), or `type:id` (`user:42`). An `actors.only` list is also honoured. |
| `filters.tags.only` / `.except` | list | `[]` / `[]` | Tag patterns. |
| `filters.environments.only` / `.except` | list | `[]` / `[]` | Environment names, evaluated per entry (the coarse `environments` key above disables recording entirely). |
| `filters.models.only` / `.except` | list | `[]` / `[]` | Subject classes: exact class, parent class or interface (`is_a`), or wildcard (`App\Models\Internal\*`). |
| `filters.request_parts.only` / `.except` | list | `[]` / `['cookies', 'session']` | `RequestPart` values withheld from entries: `headers`, `query`, `body`, `files`, `cookies`, `session`, `route_params`, `response_headers`, `response_body`, `ip`, `user_agent`. |
| `filters.severity.min` | string | `debug` | Minimum severity: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |
| `filters.rules` | list | `[]` | Extra rules: `FilterRuleInterface` class names (resolved from the container) or closures `fn (ActivityDraft $draft): FilterDecision`. Closures prevent `config:cache`; prefer classes. |
| `overrides` | map | `[]` | Pattern (route name, URL, or path) => partial `filters` array merged over the global filters for matching requests. First matching pattern wins. Example: `'admin/*' => ['request_parts' => ['only' => ['query']]]`. |

## Privacy

See [Privacy](privacy.md) for precedence and the reveal/erasure flows.

| Key | Type | Default | Description |
|---|---|---|---|
| `privacy.default_mode` | string | `mask` | Mode applied to keys matching `sensitive_key_patterns`: `mask`, `partial`, `hash`, `encrypt`, `drop`, or `keep`. |
| `privacy.paths` | map | `[]` | Dot-path => mode. `*` matches one segment, `**` any depth, e.g. `'billing.**.iban' => 'encrypt'`, `'request.headers.x-api-key' => 'drop'`. Highest precedence. |
| `privacy.sensitive_key_patterns` | list | `['*password*', '*token*', '*secret*', '*api_key*', '*authorization*', '*otp*', '*iban*', '*card*', '*cvv*']` | Case-insensitive key-name patterns redacted with `default_mode` at any depth. |
| `privacy.partial_key_patterns` | list | `['*email*', '*phone*', '*mobile*', '*first_name*', '*last_name*', '*full_name*', '*recipient*', 'username']` | Key-name patterns partially masked (`j***@example.com`, `J*** D***`, phone numbers keep `+`, up to three leading and two trailing digits). |
| `privacy.detect_email_values` | bool | `true` | Partially mask any string value that is a valid email address, even under an unmatched key. Never overrides an explicit rule. |
| `privacy.mask.char` | string | `*` | Mask character (first character used). |
| `privacy.mask.keep_prefix` | int (0–64) | `0` | Characters kept at the start of masked values. |
| `privacy.mask.keep_suffix` | int (0–64) | `0` | Characters kept at the end of masked values. |
| `privacy.ip.mode` | string | `truncate` | `truncate` zeroes host bits of the request IP. Any other value stores the IP unchanged; withhold it entirely with `filters.request_parts.except = ['ip']`. |
| `privacy.ip.ipv4_bits` | int (0–32) | `24` | IPv4 prefix bits kept (`203.0.113.7` → `203.0.113.0`). |
| `privacy.ip.ipv6_bits` | int (0–128) | `48` | IPv6 prefix bits kept. |
| `privacy.actor_label` | string | `resolve_at_read` | `keep` stores the actor display label. Any other value stores only actor type and id; labels are resolved when reading through `ActivityIdentityResolverInterface`. |
| `privacy.reveal_audit_driver` | ?string | `null` | Driver that durably records secret reveals. Production requires `database`; otherwise reveal is refused. Outside production `null` uses the active driver. |
| `privacy.erasure_connection` | ?string | `null` | Database connection that stores erasure barriers, receipts, and delivery attempts. Also the connection whose open transaction defers delivery. |
| `privacy.erasure_barrier_days` | int (1–36,500) | `31` | Lifetime of erasure barriers. Must exceed `transport.spool.max_age_days` so a replayed spool cannot resurrect erased data. |
| `privacy.erasure_max_barriers` | int (1–1,000,000) | `100000` | Quota of active barriers; erasure fails closed when full. |
| `privacy.erasure_max_receipts` | int (1–1,000,000) | `100000` | Quota of stored erasure receipts. |
| `privacy.delivery_max_attempts` | int (1–1,000,000) | `100000` | Quota of tracked in-flight remote delivery attempts. |

## Retention

Used by `activity-log:prune`. Values are whole days from 1 to 36,500; unknown keys or malformed values abort pruning before anything is deleted.

| Key | Type | Default | Description |
|---|---|---|---|
| `retention.default_days` | int | `30` | Base retention. |
| `retention.channels` | map channel => days | `['auth' => 365]` | Per-channel retention. |
| `retention.severities` | map severity => days | `[]` | Per-severity retention; wins over channel rules. |
| `retention.tags` | map tag => days | `[]` | Can only shorten the base window. Rejected by the Loki driver. |

Precedence: severity, then channel, then default; tag rules apply on top and may only shorten. With Loki, retention is enforced by Loki itself (`activity-log:loki-config` prints a matching `limits_config` fragment).

## Integrity

| Key | Type | Default | Description |
|---|---|---|---|
| `integrity.enabled` | bool | `false` | Seal entries of the listed channels into an HMAC-authenticated, segmented ledger. Verify with `activity-log:verify`. |
| `integrity.channels` | list | `['auth']` | Channels sealed and required during verification. |
| `integrity.connection` | ?string | `null` | Connection that stores the ledger tables. |
| `integrity.segment_size` | int (1–10,000) | `1000` | Entries per ledger segment. |

## Sampling

Deterministic per `trace_id`. Rates are clamped to `0.0`–`1.0`.

| Key | Type | Default | Description |
|---|---|---|---|
| `sampling.rate` | float | `1.0` | Global rate. |
| `sampling.channels` | map channel => rate | `[]` | Per-channel rate, e.g. `['http' => 0.1]`. |
| `sampling.routes` | map pattern => rate | `[]` | Per-route-name rate, e.g. `['orders.*' => 1.0]`. Checked before channel rates. |

The bucket for a trace is the same for every entry, so entries sharing a trace and a rate are kept or dropped together. Route rates only apply to entries that carry a request snapshot (`http.request`); other entries of the same request use their channel rate or the global rate. Use equal rates when complete traces matter.

## Transport

How buffered entries reach the driver. See [Performance](performance.md).

| Key | Type | Default | Description |
|---|---|---|---|
| `transport.driver` | string | `sync` | `sync` writes during the flush (after the response is sent), `queue` dispatches one `PersistActivityBatchJob` per batch, `stream` writes JSON lines to `transport.stream.target`. |
| `transport.buffer` | int (1–100) | `100` | Entries buffered per scope before a forced flush (also bounded by 2 MiB). |
| `transport.on_failure` | string | `spool` | When delivery throws: `spool` (append to the spool file), `ignore` (drop), or `throw`. |
| `transport.queue.connection` | ?string | `null` | Queue connection for the `queue` transport. |
| `transport.queue.queue` | ?string | `null` | Queue name for the `queue` transport. |
| `transport.spool.path` | ?string | `storage/eagle-eye/spool.log` | Generic spool file. Drain with `activity-log:ship`. |
| `transport.spool.max_bytes` | int (64 KiB–512 MiB) | `10485760` | Spool quota. |
| `transport.spool.max_age_days` | int (1–36,499) | `30` | Oldest entry age accepted on replay. |
| `transport.stream.target` | string | `php://stdout` | Target of the `stream` transport. For Loki collectors use `drivers.loki.write.path = stdout` instead. |

## API

The optional JSON retrieval API. See [API](api.md).

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `api.enabled` | `EAGLE_EYE_API_ENABLED` | bool | `false` | Registers the `eagle-eye.activity-logs.*` routes. |
| `api.prefix` | `EAGLE_EYE_API_PREFIX` | string | `api/v1` | Route prefix; routes live under `{prefix}/activity-logs`. |
| `api.middleware` | — | list | `['api']` | Middleware for the route group. Add authentication (for example `auth:sanctum`); authorization is enforced by `LogAccessPolicyInterface`. |

## Capture

Every adapter has an `enabled` switch and a `via` class that implements `CaptureAdapterInterface` (replace it to customise capture). Adapters never throw into application code; failures are reported through the exception handler.

### `capture.http`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_HTTP` | bool | `true` | Pushes the global terminable `CaptureHttpActivityMiddleware`; `http.request` is recorded after the response is sent. |
| `via` | — | class | `HttpCapture` | Capture implementation. |
| `parts` | — | list | `['query', 'route_params']` | Request parts stored in `payload.request` (`RequestPart` values). Parts withheld by `filters.request_parts` are always removed. |
| `body_routes` | `EAGLE_EYE_CAPTURE_HTTP_BODY_ROUTES` | list | `[]` | Route names or paths (wildcards allowed) whose request body, uploaded file metadata, and JSON response body are stored. |
| `body_max_bytes` | — | int (1,024–16,384) | `16384` | Request bodies above this size are omitted. |
| `files_max` | — | int (0–100) | `20` | Maximum uploaded files described (name masked, size, MIME type, upload error; never contents or paths). |
| `response.body` | — | string | `errors_only` | JSON response body capture outside `body_routes`: `errors_only` (status ≥ 400), `always`, or any other value to disable. Streamed and binary responses are never read. |
| `response.max_bytes` | — | int (64–16,384) | `16384` | Larger response bodies are omitted. |

### `capture.model`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_MODEL` | bool | `true` | Wildcard `eloquent.*` listener; stores changed attributes only. Entries inside a transaction are recorded after commit and discarded on rollback. |
| `via` | — | class | `EloquentCapture` | Capture implementation. |
| `events` | — | list | `['created', 'updated', 'deleted', 'restored', 'forceDeleted']` | Model events recorded as `model.<event>`. |
| `ignored_change_fields` | — | list | `['created_at', 'updated_at', 'deleted_at']` | Fields left out of the changed-field list in titles and summaries. |
| `states.fields` | — | list | `['status', 'state']` | Attributes watched for failure transitions on update. |
| `states.failure_values` | — | list | `['failed', 'error', 'rejected', 'bounced', 'cancelled']` | Lower-case values that mark a failure. |
| `states.severity` | — | string | `warning` | Severity of an update that moves a watched field to a failure value; `payload.state` is added. |

### `capture.auth`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_AUTH` | bool | `true` | Records `auth.login`, `auth.failed`, `auth.registered`, `auth.logout`, `auth.password_reset`, `auth.verified`, `auth.lockout`, and `auth.other_device_logout` with the guard name. |
| `via` | — | class | `AuthCapture` | Capture implementation. |

### `capture.queue`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_QUEUE` | bool | `true` | Queue lifecycle capture. Trace propagation into jobs works regardless of this switch. |
| `via` | — | class | `QueueCapture` | Capture implementation. |
| `events` | — | list | `['queued', 'processed', 'failed']` | Also available: `processing`, `exception`. Recorded as `queue.<event>`. |

### `capture.console`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_CONSOLE` | bool | `true` | Artisan command and scheduled task capture. |
| `via` | — | class | `ConsoleCapture` | Capture implementation. |
| `events` | — | list | `['finished']` | Also available: `started`. Recorded as `console.<event>` with arguments, options, exit code, duration, and memory. |
| `except` | — | list | `['tinker', 'queue:work', 'queue:listen', 'horizon*', 'schedule:work', 'octane:*', 'activity-log:*', 'list', 'help', 'about', 'route:list', 'config:show', 'schedule:list', 'event:list', 'model:show', 'db:show', '*:list']` | Commands never captured (wildcards allowed). Long-running workers and read-only commands are excluded by default. |

### `capture.mail`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_MAIL` | bool | `true` | Mail capture. Message bodies are never stored. |
| `via` | — | class | `MailCapture` | Capture implementation. |
| `events` | — | list | `['sent', 'failed']` | Also available: `sending`. |
| `store_recipients` | — | string \| false | `masked` | `masked` stores partially masked `to` addresses, `full` stores them verbatim (declared `keep`), `false`/`none` stores none. Counts and recipient domains are always stored. |
| `max_recipients` | — | int | `5` | Maximum recipients stored per message. |

### `capture.notification`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_NOTIFICATION` | bool | `true` | Notification capture (type, channel, notifiable, error). Rendered content is never stored. |
| `via` | — | class | `NotificationCapture` | Capture implementation. |
| `events` | — | list | `['sent', 'failed']` | Also available: `sending`. |

### `capture.outbound_http`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_OUTBOUND_HTTP` | bool | `true` | Laravel `Http` client capture and W3C `traceparent` injection. For other Guzzle clients push the tagged `eagle-eye.guzzle_middleware` (see [Extending](extending.md)). |
| `via` | — | class | `OutboundHttpCapture` | Capture implementation. |
| `events` | — | list | `['response', 'failed']` | Also available: `request`. |
| `error_body_max_bytes` | — | int | `4096` | Maximum JSON error-response body (status ≥ 400) stored; `0` disables. Only seekable bodies are read, and the stream position is restored. |
| `except_hosts` | — | list | `[]` | Hosts never captured (wildcards allowed). The configured Loki read/write hosts are always excluded. |

### `capture.exception`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_EXCEPTION` | bool | `true` | Records reported exceptions, deduplicated per request/job (up to 256 fingerprints). |
| `via` | — | class | `ExceptionCapture` | Capture implementation. |

### `capture.query`

| Key | Env | Type | Default | Description |
|---|---|---|---|---|
| `enabled` | `EAGLE_EYE_CAPTURE_QUERY` | bool | `false` | Records a `query.slow` entry for every query at or above `slow_threshold_ms` (SQL text and binding count, never binding values). |
| `summary` | `EAGLE_EYE_CAPTURE_QUERY_SUMMARY` | bool | `true` | Attaches `payload.queries` to `http.request`, `queue.processed`/`queue.failed`, and `console.finished` entries. |
| `via` | — | class | `QueryCapture` | Capture implementation. |
| `slow_threshold_ms` | `EAGLE_EYE_QUERY_SLOW_MS` | int | `500` | Threshold for `query.slow` events and the summary `slow` list. |

The summary has the shape:

```json
{
  "count": 42,
  "total_ms": 18.37,
  "slow": [{"sql": "select * from orders where id = ?", "ms": 812.4}],
  "duplicates": [{"sql": "select * from users where id = ?", "count": 9, "total_ms": 3.1}],
  "nested": {"count": 12, "total_ms": 4.2},
  "truncated": false
}
```

`slow` lists up to 5 statements at or above the threshold (empty when none qualify), `duplicates` the top 5 fingerprints executed at least 3 times, and `nested` the totals of jobs run synchronously inside the unit of work. Statements are fingerprinted (literals replaced), at most 50 distinct fingerprints are kept per unit of work, and bindings are never stored.

## Payload

Normalisation budgets for `payload`. Values above the hard limits are clamped.

| Key | Type | Default | Description |
|---|---|---|---|
| `payload.max_bytes` | int (256–1,048,576) | `65536` | Maximum encoded payload size; larger payloads are replaced by truncation metadata (`__truncated`). |
| `payload.max_depth` | int (1–32) | `8` | Maximum nesting depth. |
| `payload.max_array_items` | int (1–1,000) | `100` | Items kept per array or iterable. |
| `payload.max_string_length` | int (1–65,536) | `4096` | Bytes kept per string. |
| `payload.max_nodes` | int (1–100,000) | `2000` | Values visited per payload. |

## Subjects

| Key | Type | Default | Description |
|---|---|---|---|
| `subjects.label_attributes` | list | `['name', 'title', 'label', 'reference', 'subject', 'code']` | Attributes tried, in order, for a subject's display label. Attributes whose privacy mode is not `keep` are skipped. |
| `subjects.per_class` | map | `[]` | Per-class settings: `[App\Models\Invoice::class => ['label_attribute' => 'number']]`. `activity-log:cache` warms metadata for these classes. |

Label resolution order: `DescribesActivity::activityLogLabel()`, `#[ActivityLabel]`, `subjects.per_class`, `subjects.label_attributes`, then `Headline Class Name #<id>`.

## Hard limits

These ceilings apply regardless of configuration:

| Limit | Value |
|---|---|
| Stored entry (complete envelope) | 128 KiB |
| Payload | 64 KiB |
| String value | 8 KiB |
| Payload nodes | 2,000 |
| Tags per entry / secret references per entry | 50 / 50 |
| In-memory buffer | 100 entries or 2 MiB |
| Delivery batch | 100 entries or 1 MiB |
| Loki spool file | 256 MiB |
| Loki response decoded | 2 MiB |
| Aggregate groups | 1,000 |
| Time-series result | 400 buckets, 5,000 points |
