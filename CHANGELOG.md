# Changelog

All notable changes to `dotswan/eagle-eye` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 1.0.0 - 2026-10-03

Initial public release.

### Capture

- Zero-code capture of HTTP requests (global terminable middleware, recorded after the response is sent), Eloquent model changes (diff-only, deferred until the surrounding transaction commits), authentication events, queue jobs, console and scheduled commands, mail, notifications, outbound HTTP (Laravel `Http` client and any Guzzle client through the tagged `eagle-eye.guzzle_middleware`), reported exceptions (deduplicated per unit of work), and opt-in slow queries.
- Every capture channel can be switched off with an environment variable and its emitted events chosen per channel (`capture.*.events`).
- Per-request, per-job, and per-command SQL summary (`payload.queries`): count, total time, duplicate fingerprints, a thresholded `slow` list, and nested job totals. Bindings are never stored.
- Opt-in request and response body capture for selected routes (`capture.http.body_routes`) with byte limits and file metadata only.
- Manual recording through the `EagleEye` facade, the `eagleEye()` helper, and `ActivityDraft` for full control over actor, subject, tags, title, and payload.
- PHP attributes for subject metadata (`ActivitySubject`, `ActivityLabel`, `ActivityTags`, `ActivityField`, `DoNotLog`) and the `DescribesActivity` / `DeclaresActivityPrivacy` contracts.
- Human-readable, localised titles and summaries for every channel, with a stored `title_source` so titles can be re-rendered in another locale. Translations are publishable (`eagle-eye-lang`).
- Two-stage filtering (cheap pre-pipeline gate plus a deny-first rule chain) by URL, method, channel, actor, tag, environment, model class, severity, and custom rules, with per-route overrides and request-part projection.
- Deterministic trace-based sampling with global, per-channel, and per-route rates.
- Automatic, static, scoped, and attribute-driven tags with a per-entry cap.

### Drivers

- `database` driver (default): idempotent batched writes, keyset pagination, JSON tag and full-text search on SQLite, MySQL, and PostgreSQL, aggregation, time series, pruning, and per-subject erasure. One publishable migration.
- `loki` driver for Grafana Loki 3.x: stdout (collector) mode, batched push with gzip, tenant and auth, and a bounded disk spool; structured-metadata or JSON LogQL translation; keyset pagination; metric aggregation; boot-time label cardinality guard.
- `index` driver: a header-only SQL projection used as a query accelerator.
- `multi` driver: a full-body primary plus an accelerator, with per-child failure policies (`throw`, `ignore`, `fallback`).
- `memory` (bounded, for tests) and `null` drivers.
- Segregated driver contracts (write, query, maintenance, series), `Capability` flags, a reusable driver conformance suite (`DriverContractTests`), and the `activity-log:make-driver` scaffolder.
- Off-path buffered transport flushed on request termination, queue job completion, Octane request termination, and shutdown, with `sync`, `queue`, and `stream` strategies and a bounded on-disk spool fallback. Delivery started inside an open database transaction is deferred until it commits.

### Privacy & security

- Automatic redaction with path rules (`*` / `**` wildcards), sensitive key patterns, partial masking for emails, phone numbers, and names, email-value detection, and `mask`, `partial`, `hash`, `drop`, `encrypt`, and `keep` modes.
- Encrypted secrets with blind indexes and a policy-gated, durably audited `reveal()` that survives application key rotation.
- IP truncation, read-time actor labels (`privacy.actor_label = resolve_at_read`), and per-model privacy declarations.
- Subject erasure (`activity-log:forget`) with HMAC replay barriers, authenticated receipts, and delivery-attempt coordination across buffers, queues, spools, and remote drivers.
- Retention by channel, severity, and tag (`activity-log:prune`).
- Optional HMAC-authenticated integrity ledger (`activity-log:verify`).
- Hard ceilings on payload, envelope, buffer, batch, and spool sizes that configuration cannot raise.

### Correlation & tracing

- One trace per HTTP request, queued job, or console command, with causal `causation_id` chains between entries.
- W3C `traceparent` adoption on inbound requests and injection on outbound requests.
- Zero-code trace propagation into queued jobs.
- Trace retrieval through `forTrace()`, the `activity-log:trace` command, and the API trace tree endpoint.

### Querying & API

- Immutable, fluent query builder (`EagleEye::query()`) with actor, subject, subject class, trace, channel, action, severity, status, tag, full-text, and time-range filters, opaque cursor pagination, counts, grouped and top-K aggregation, and aligned time series.
- `activityTrail()` Eloquent macro for subject and actor histories.
- Opt-in JSON retrieval API (`eagle-eye.api.enabled`): list, detail, trace tree, stats, tag autocomplete, audited secret reveal, and capability-aware live tail. The v1 envelope is frozen by a snapshot test and mirrored by a TypeScript declaration (`resources/js/types/eagle-eye.d.ts`).
- `EagleEye::fake()` testing helper with assertion methods.

### Tooling & commands

- `activity-log:doctor`, `stats`, `tail`, `trail`, `trace`, `export` (bounded-memory NDJSON), `prune`, `forget`, `verify`, `ship`, `loki-ship`, `loki-config` (Alloy stage and Loki limits fragment), `cache`, and `make-driver`.
