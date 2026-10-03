# Architecture

Eagle Eye separates **capture**, **processing**, **delivery**, **storage**, and **retrieval**. Each stage depends only on contracts, so any stage can be replaced without touching the others.

```text
 capture adapters / EagleEye::record()
          │  ActivityDraft
          ▼
 Recorder ── gate (enabled, environment, cheap filters)
          │
          ▼
 PipelineRunner ── configured pipes (actor, context, trace, filters, sampling,
          │        request parts, enrichers, normalise, redact, encrypt,
          │        subject metadata, tags, title, budget, integrity)
          │  ActivityEntry (immutable)
          ▼
 ActivityBuffer (per scope, ≤100 entries / 2 MiB)
          │  flush on terminate / job end / Octane / shutdown / full buffer
          ▼
 Flusher ── deferred after commit when a transaction is open
          ▼
 GuardedTransport ── erasure barrier check ── sync | queue | stream
          │                                     └─ on failure: spool | ignore | throw
          ▼
 Driver (database | loki | index | multi | memory | null | custom)
          ▲
          │
 ActivityRepository ── find / search / count / aggregate / series / tags / reveal
          ▲
 EagleEye::query(), activityTrail(), JSON API, console commands
```

## Data model

- **`ActivityDraft`** is the mutable working object that capture code and pipes fill in.
- **`ActivityEntry`** is the immutable, versioned envelope that drivers store. Top-level, indexable metadata (id, action, channel, severity, actor, subject, trace, request, tags, timestamps, title, summary) is separate from variable channel data in `payload`. Encrypted values live in `secrets` and are referenced from the payload by `{"__secret": "<path>"}`.
- `ActivityEntry::toStorage()` / `fromStorage()` define the lossless storage round trip that every driver must preserve. The JSON API serialises the same envelope (`schema_version` 1), mirrored by `resources/js/types/eagle-eye.d.ts`.
- Entry ids are ULIDs, so ids sort by creation time.

## Capture

Each channel is a `CaptureAdapterInterface` implementation registered by the service provider when `capture.<channel>.enabled` is true. Adapters listen to framework events (Eloquent, auth, queue, console, mail, notification, HTTP client, exception reporting, `QueryExecuted`); HTTP capture uses a global terminable middleware. Adapters:

- never throw into application code (failures are reported through the exception handler),
- never lazy-load relations or consume response streams,
- run their own work under `CaptureSuppression`, so Eagle Eye's internal queries and HTTP calls are never captured.

## Processing pipeline

`config('eagle-eye.pipeline')` is an ordered list of `ActivityPipeInterface` classes executed through Laravel's `Pipeline`. A pipe returns the next pipe's result, or `null` to drop the draft. Ordering is configuration, but privacy pipes must run before anything that persists or forwards data. Enrichers (`DraftEnricherInterface`, tag `eagle-eye.enrichers`) run inside `RunEnrichers`, before redaction, so whatever they add is redacted too.

## Delivery

Recording is buffered per scope and delivered after the response, job, or command, in batches of at most 100 entries or 1 MiB. The transport is chosen by `transport.driver`. `GuardedTransport` enforces erasure barriers (entries of an erased subject are discarded, even from replayed spools) and applies `transport.on_failure`. Delivery inside an open transaction is postponed to an after-commit callback.

## Storage and capabilities

Drivers implement segregated contracts (write, query, maintenance, series) and advertise optional features through `Capability`. Consumers check capabilities and degrade explicitly instead of guessing from the driver name. The `multi` driver composes a full-body primary with a header-only accelerator. See [Drivers](drivers.md).

## Retrieval

`ActivityRepositoryInterface` is the only read surface used by `EagleEye::query()`, the `activityTrail()` macro, the JSON API, and the console commands. It also implements `reveal()`, which checks `LogAccessPolicyInterface`, writes a durable audit entry, and only then decrypts. `ActivityEntryPresenterInterface` and `ActivityIdentityResolverInterface` resolve display labels in batches at read time, so labels withheld at write time (`privacy.actor_label = resolve_at_read`) can be shown without storing them.

## Scoping and long-running processes

Per-unit-of-work services (`Recorder`, `ActivityBuffer`, `Flusher`, `TraceManager`, `PipelineRunner`, `FilterChain`, `QuerySummaryCollector`, `CaptureSuppression`, `ExceptionDeduplicator`) are bound with `scoped()`. Laravel resets scoped instances between Octane requests and queue jobs, so no state leaks between units of work and no package-specific reset hooks are required.

## Package boundaries

- Runtime dependencies are PHP 8.4+, `ext-mbstring`, and `illuminate/*` only. Eloquent, the queue, the HTTP client, and Octane are used when present and guarded when absent. CI enforces this with a dependency audit and Deptrac layer rules.
- Package code never references application namespaces; host integrations depend on Eagle Eye contracts (`src/Contracts`).
- Public API: everything in `src/Contracts`, `src/Data`, `src/Enums`, `src/Attributes`, the `EagleEye` facade, the helpers, the configuration keys, the console commands, the API routes, and the stored envelope. Classes marked `@internal` may change in minor releases.
- Loki stream labels stay bounded; identifiers live in structured metadata and the JSON body.
- SQL drivers delete synchronously; Loki retention and deletion are performed by Loki.

## Architecture decision records

Background for the main design decisions is recorded in [`docs/adr`](adr/):

1. [Standalone package boundary](adr/0001-standalone-package-boundary.md)
2. [Canonical activity envelope](adr/0002-canonical-activity-envelope.md)
3. [Laravel-native encryption](adr/0003-laravel-native-encryption.md)
4. [Segregated driver contracts](adr/0004-segregated-driver-contracts.md)
5. [Zero-touch Eloquent trails](adr/0005-zero-touch-eloquent-trails.md)
6. [Request-scoped trace correlation](adr/0006-request-scoped-trace-correlation.md)
7. [Persistent integrity and retention](adr/0007-scoped-integrity-and-retention.md)

See also [Correlation](correlation.md), [Privacy](privacy.md), and [Performance](performance.md).
