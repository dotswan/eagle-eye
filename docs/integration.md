# Integration and compatibility

This page lists everything Eagle Eye registers in a Laravel application, so you can check for clashes with your code or other packages before installing it.

## Service provider and discovery

| Item | Value |
|---|---|
| Service provider | `Dotswan\EagleEye\EagleEyeServiceProvider` (auto-discovered through `extra.laravel.providers`) |
| Facade | `Dotswan\EagleEye\Facades\EagleEye`. No global alias is registered; import the class or add your own alias. |
| Root namespace | `Dotswan\EagleEye\` |

To opt out of auto-discovery, add `"dont-discover": ["dotswan/eagle-eye"]` under `extra.laravel` in your application's `composer.json` and register the provider yourself.

## Configuration, translations, migrations

| Item | Value |
|---|---|
| Config key / file | `eagle-eye` / `config/eagle-eye.php` (merged automatically; publishing is optional) |
| Translation namespace | `eagle-eye` (`__('eagle-eye::titles.…')`) |
| Publish tags | `eagle-eye-config` → `config/eagle-eye.php`; `eagle-eye-migrations` → `database/migrations`; `eagle-eye-lang` → `lang/vendor/eagle-eye` |
| Migration file | `0001_01_01_000000_create_activity_logs_table.php` (one migration, no foreign keys to your tables) |
| Environment variables | `EAGLE_EYE_*`, plus generic `LOKI_*` names for the Loki driver (see [Configuration](configuration.md#environment-variables)) |

The migration is not loaded automatically; it runs only after you publish it. Its timestamp sorts it next to Laravel's default `0001_01_01_*` migrations.

### Database tables

| Table | Purpose |
|---|---|
| `activity_logs` | Entries of the `database` driver (name configurable: `drivers.database.table`) |
| `activity_log_index` | Header projection for the `index`/`multi` drivers (`drivers.index.table`) |
| `activity_security_state` | Fingerprint of `EAGLE_EYE_SECURITY_KEY` |
| `activity_delivery_attempts` | In-flight remote deliveries, coordinated with erasure |
| `activity_erasure_barriers` | HMAC replay barriers for erased subjects |
| `activity_erasure_receipts` | Authenticated erasure outcomes |
| `activity_integrity_state` | Integrity ledger head |
| `activity_integrity_segments` | Integrity ledger segments |
| `activity_integrity_entries` | Integrity ledger entries |

Only `activity_logs` and `activity_log_index` names are configurable. Rename the others only by editing the published migration and binding your own implementations; this is not supported.

## HTTP

| Item | Value |
|---|---|
| Global middleware | `Dotswan\EagleEye\Http\Middleware\CaptureHttpActivityMiddleware` is pushed onto the HTTP kernel when `capture.http.enabled` is true. It is terminable and records after the response is sent. No middleware alias is registered. |
| Response header | Every response gets `X-Correlation-Id` (the active correlation id). |
| Request attribute | `eagle_eye.started_at` on the request attributes bag (start time). |
| Outbound headers | When outbound capture is enabled, a global HTTP client request middleware (`Http::globalRequestMiddleware`) adds `traceparent` and `X-Correlation-Id` to requests made with Laravel's `Http` client. |
| Routes | Only when `api.enabled` is true, under `{api.prefix}` (default `api/v1`) with `api.middleware` (default `['api']`). |

API routes:

| Method | URI (default prefix) | Name |
|---|---|---|
| GET | `api/v1/activity-logs` | `eagle-eye.activity-logs.index` |
| GET | `api/v1/activity-logs/traces/{traceId}` | `eagle-eye.activity-logs.trace` |
| GET | `api/v1/activity-logs/stats` | `eagle-eye.activity-logs.stats` |
| GET | `api/v1/activity-logs/tags` | `eagle-eye.activity-logs.tags` |
| GET | `api/v1/activity-logs/stream` | `eagle-eye.activity-logs.stream` |
| GET | `api/v1/activity-logs/{id}` | `eagle-eye.activity-logs.show` |
| POST | `api/v1/activity-logs/{id}/reveal` | `eagle-eye.activity-logs.reveal` |

If your application already serves `/api/v1/activity-logs`, change `EAGLE_EYE_API_PREFIX`. See [API](api.md).

## Authorization gates

The default `LogAccessPolicyInterface` implementation (`GateAccessPolicy`) checks two Laravel gate abilities that you define:

| Ability | Used for |
|---|---|
| `viewActivityLog` | `canView()`: listing, detail, trace, stats, tags, stream |
| `revealActivityLog` | `canReveal()`: decrypting a secret value (receives the entry and the secret path) |

Undefined gates deny access. Bind your own `LogAccessPolicyInterface` to use policies or roles instead.

## Console commands

All commands share the `activity-log:` prefix:

| Command | Purpose |
|---|---|
| `activity-log:doctor` | Driver health, capabilities, retention, spool, pipeline, and Loki label diagnostics |
| `activity-log:stats {--group-by=channel} {--interval=day}` | Storage and aggregation statistics |
| `activity-log:tail {--limit=20} {--follow}` | Newest entries (`--follow` requires a live-tail driver) |
| `activity-log:trail {--user=} {--subject=} {--limit=50}` | Recent activity of an actor (`type:id`) or subject (`type:id`) |
| `activity-log:trace {id}` | Every entry of one trace, in causal order |
| `activity-log:export {--output=php://stdout} {--chunk=500}` | NDJSON export with bounded memory |
| `activity-log:prune` | Apply retention rules |
| `activity-log:forget {type} {id}` | Erase one data subject (with `--resolve-attempt=` / `--settlement-evidence=` for uncertain remote deliveries) |
| `activity-log:verify` | Verify integrity chains |
| `activity-log:ship {--chunk=500}` | Replay the transport spool |
| `activity-log:loki-ship {--chunk=500}` | Replay the Loki push spool |
| `activity-log:loki-config {--retention-days=}` | Print the Alloy stage and Loki `limits_config` fragment |
| `activity-log:cache` | Warm subject metadata for `subjects.per_class` |
| `activity-log:make-driver {name} {--path=}` | Scaffold a custom driver and its contract test |

## Container

### Bindings you may replace

| Contract | Default |
|---|---|
| `Contracts\EagleEyeInterface` | `EagleEye` (scoped) |
| `Contracts\Repository\ActivityRepositoryInterface` | `Repository\ActivityRepository` |
| `Contracts\Driver\ActivityLogDriverFactoryInterface` | `Drivers\DriverManager` |
| `Contracts\Resolution\ActorResolverInterface` | `Resolution\AuthActorResolver` |
| `Contracts\Resolution\SubjectResolverInterface` | `Resolution\ConventionSubjectResolver` |
| `Contracts\Resolution\FieldLabelResolverInterface` | `Resolution\AttributeFieldLabelResolver` |
| `Contracts\Resolution\ActivityIdentityResolverInterface` | `Resolution\StoredActivityIdentityResolver` |
| `Contracts\Resolution\ActivityEntryPresenterInterface` | `Resolution\ActivityEntryPresenter` |
| `Contracts\Resolution\PayloadNormalizerInterface` | `Resolution\PayloadNormalizer` |
| `Contracts\Title\TitleResolverInterface` | `Title\TitleResolverChain` |
| `Contracts\Title\TemplateRendererInterface` | `Title\PlaceholderTemplateRenderer` |
| `Contracts\Security\LogAccessPolicyInterface` | `Security\GateAccessPolicy` |
| `Contracts\Security\CipherInterface` | `Security\LaravelCipher` |
| `Contracts\Security\BlindIndexInterface` | `Security\HmacBlindIndex` |
| `Contracts\Correlation\TracePropagatorInterface` | `Correlation\W3CTracePropagator` |
| `Contracts\Sampling\SamplerInterface` | `Sampling\DeterministicSampler` |

All names are relative to `Dotswan\EagleEye\`. See [Extending](extending.md) for examples.

### Container tags

| Tag | Purpose |
|---|---|
| `eagle-eye.title_resolvers` | Extra `TitleResolverInterface` implementations, tried before the built-ins |
| `eagle-eye.enrichers` | `DraftEnricherInterface` implementations run by the `RunEnrichers` pipe |
| `eagle-eye.guzzle_middleware` | Holds `GuzzleCaptureMiddleware` for SDKs with their own Guzzle client |

## Global hooks

| Hook | Effect |
|---|---|
| `Queue::createPayloadUsing()` | Adds an `eagleEyeTrace` key to every queued job payload (trace context, a few hundred bytes). |
| `JobProcessing` listener | Restores the trace in the worker. |
| `Builder::macro('activityTrail')` | Adds `activityTrail()` to every Eloquent query builder (and therefore every model). |
| Event listeners | One per enabled capture channel (`eloquent.*`, auth, queue, console, mail, notification, HTTP client, `MessageLogged` for reported exceptions, `QueryExecuted`). |
| `terminating()` and `register_shutdown_function()` | Flush the activity buffer. |

## Helper functions

Defined in `src/helpers.php`, each guarded by `function_exists`:

| Function | Equivalent |
|---|---|
| `eagleEye(): EagleEyeInterface` | `app(EagleEyeInterface::class)` |
| `eagle_eye_tag(array $tags, Closure $callback): mixed` | `EagleEye::withTags($tags, $callback)` |
| `eagle_eye_silent(Closure $callback): mixed` | `EagleEye::withoutLogging($callback)` |

Because of the `function_exists` guards, a function with the same name defined earlier by your application wins silently; avoid defining these names yourself.

## Files written at runtime

| Path | Written by |
|---|---|
| `storage/eagle-eye/spool.log` (`transport.spool.path`) | Transport fallback when delivery fails |
| `storage/logs/eagle-eye-loki.spool` (`LOKI_SPOOL_PATH`) | Loki push writer fallback |

Make sure these paths are on persistent, writable storage in containerised deployments, or point them at a mounted volume.

## Coexisting with other packages

### spatie/laravel-activitylog

The two packages can be installed side by side; nothing they register collides:

| | spatie/laravel-activitylog | Eagle Eye |
|---|---|---|
| Table | `activity_log` | `activity_logs` (plus the tables above) |
| Helper | `activity()` | `eagleEye()`, `eagle_eye_tag()`, `eagle_eye_silent()` |
| Model integration | `LogsActivity` trait | Automatic `eloquent.*` listener, `activityTrail()` macro |
| Config | `config/activitylog.php` | `config/eagle-eye.php` |
| Commands | `activitylog:clean` | `activity-log:*` |

Both will record model changes if both are active. If you migrate gradually, either exclude the models still handled by the other package with `filters.models.except`, or disable `capture.model` until the migration is done. Note the similar table names `activity_log` and `activity_logs` when writing raw queries.

### Laravel Telescope, Pulse, Horizon, Debugbar

Their routes (`telescope/*`, `pulse/*`, `horizon/*`, `_debugbar/*`) are excluded by default, as are long-running commands such as `horizon*` and `queue:work`. Eagle Eye and Telescope can run together; Eagle Eye is the durable audit trail, Telescope the short-lived debugging tool.

### Other tracing tools (OpenTelemetry, Sentry)

Eagle Eye adopts an inbound W3C `traceparent` and injects one on outbound Laravel HTTP client requests. If another tool also sets `traceparent` on outgoing requests, the one that runs last wins. To keep another tracer authoritative for outbound headers, set `EAGLE_EYE_CAPTURE_OUTBOUND_HTTP=false` (this disables both outbound capture and header injection) and, if you still want outbound entries, push the `eagle-eye.guzzle_middleware` onto the clients you care about; it records calls without injecting headers. Inbound adoption is unaffected, so Eagle Eye entries keep the upstream trace id.

### Livewire

Livewire update and asset requests (`livewire/*`, `livewire-*/livewire*.js`, `livewire-*/*.js.map`) are excluded from HTTP capture by default. Model changes made inside Livewire actions are still captured through the Eloquent listener.

### Octane

Supported without extra configuration. See [Performance › Octane](performance.md#octane-and-long-running-workers).
