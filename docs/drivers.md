# Drivers

A driver stores and reads activity entries. Application code never talks to a driver directly: it records through `EagleEye`/`eagleEye()` and reads through `EagleEye::query()` or `ActivityRepositoryInterface`. Select the active driver with `EAGLE_EYE_DRIVER` (`default` in the configuration).

## Shipped drivers

| Driver | Storage | Use it for |
|---|---|---|
| `database` (default) | SQL table `activity_logs` (SQLite, MySQL, PostgreSQL) | Durable storage with no extra infrastructure. Full queries, exact counts, synchronous retention and erasure. |
| `loki` | Grafana Loki 3.x | Shared observability storage at high volume. Writes through stdout, HTTP push, or a local spool. See [Loki](loki.md). |
| `index` | SQL table `activity_log_index` | Header-only projection (no payload) used as a fast query accelerator. Normally used as the `multi` accelerator, not on its own. |
| `multi` | A primary plus an optional accelerator | Loki for full bodies plus the SQL index for listings, exact counts, rankings, and unbounded actor/subject histories. |
| `memory` | Process memory, bounded | Tests. Registered automatically by `EagleEye::fake()`. Not durable. |
| `null` | Nothing | Deliberate discard. Accepts writes and returns empty results. |

All SQL tables are created by the package migration (`php artisan vendor:publish --tag=eagle-eye-migrations`). Even with the `loki` driver, run the migration: erasure barriers, receipts, delivery attempts, and the integrity ledger live in SQL.

### `database`

- Idempotent batched writes keyed by entry ULID.
- Keyset pagination on `(logged_at, id)`.
- Tag filters and full-text search (title, action, subject label, actor label, URL) on SQLite, MySQL, and PostgreSQL.
- Grouped, top-K, and time-series aggregation computed in SQL.
- `prune()` and `forget()` delete synchronously.
- Acts as the durable reveal-audit destination (`privacy.reveal_audit_driver = database`).

Settings: `drivers.database.connection`, `drivers.database.table`.

### `multi`

Writes go to the primary and then to the accelerator in the same batch. Failure handling is configured per child:

| Setting | Values | Behaviour |
|---|---|---|
| `on_failure.primary` | `throw` (default) | Primary rejection or exception fails the batch (the transport then spools it). |
| | `ignore` | Primary failures are reported as rejected entries; the accelerator is still written. |
| | `fallback` | Like `ignore`, but entries accepted by an accelerator that stores full bodies count as delivered. |
| `on_failure.accelerator` | `ignore` (default) | Accelerator errors are swallowed. |
| | `throw` | Accelerator errors fail the batch. |

Reads: listings, counts, rankings, series, and traces use the accelerator when one is configured; full-text search, JSON field queries, and full bodies use the primary. When the primary cannot return a body, `find()` looks it up in a bounded time window around the index timestamp (one second before to at most one hour after) and accepts only the exact entry id. If the body is still unavailable, the index header is returned with `payload.__eagle_eye.body_available = false`. Capabilities reflect the child that will answer each kind of query.

## Capabilities

Drivers advertise features through `Dotswan\EagleEye\Enums\Capability`. Check them instead of inferring features from a driver name:

```php
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Enums\Capability;

$driver = app(ActivityLogDriverFactoryInterface::class)->driver();

if ($driver->supports(Capability::ExactCount)) {
    // show an exact total
}
```

| Capability | database | index | memory | loki | null |
|---|---|---|---|---|---|
| `FullTextSearch` | yes | no | yes | yes | no |
| `ExactCount` | yes | yes | yes | no | yes |
| `UnboundedTimeRange` | yes | yes | yes | no | yes |
| `Aggregation` | yes | yes | yes | yes | yes |
| `RankedAggregation` | yes | yes | yes | yes | no |
| `SeriesAggregation` | yes | yes | yes | yes | yes |
| `SynchronousDeletion` | yes | yes | yes | no | yes |
| `JsonFieldQuery` | no | no | no | no | no |
| `LiveTail` | no | no | no | no | no |

`multi` reports the capabilities of the child that serves each feature. No shipped driver supports `LiveTail`, so `activity-log:tail --follow` and `GET {prefix}/activity-logs/stream` report "unsupported" (the API returns `501`) unless you register a driver that does.

Without `UnboundedTimeRange` (Loki), queries without a time range are limited to `drivers.loki.read.default_range_days`. Without `ExactCount`, counts may be estimates (`QueryResult::$exactCount` is `false`). Without `SynchronousDeletion`, `forget()` returns an `accepted_pending` outcome instead of `completed`.

## Contracts

Driver contracts are segregated so a driver implements only what it supports:

| Interface | Methods |
|---|---|
| `ActivityLogDriverInterface` (required) | `write()`, `writeBatch()` (returns `list<WriteFailure>`), `name()`, `healthy()`, `supports()` |
| `ActivityLogQueryDriverInterface` | `find()`, `search()`, `count()`, `aggregate()`, `aggregateTop()`, `tags()` |
| `ActivityLogMaintenanceDriverInterface` | `prune()`, `forget()`, `install()`, `stats()` |
| `ActivityLogSeriesDriverInterface` | `aggregateSeries()` (advertise `Capability::SeriesAggregation`) |
| `FullActivityLogDriverInterface` | Write + query + maintenance in one interface. Required for `multi` children. |
| `StoresFullActivityBodiesInterface` | Marker: the driver persists complete entries (not just headers). |

All interfaces live in `Dotswan\EagleEye\Contracts\Driver`. A write-only driver can record; query calls then throw `LogicException`, as does `aggregateSeries()` on a driver without the series contract.

## Writing a custom driver

Scaffold a driver, a configuration block, and a contract test:

```bash
php artisan activity-log:make-driver Clickhouse            # writes to app/ActivityLog
php artisan activity-log:make-driver Clickhouse --path=src/Activity
```

Register it in a service provider:

```php
use App\ActivityLog\ClickhouseDriver;
use Dotswan\EagleEye\Drivers\DriverManager;

public function boot(DriverManager $drivers): void
{
    $drivers->extend('clickhouse', fn ($app) => $app->make(ClickhouseDriver::class));
}
```

Add settings under `drivers.clickhouse` in `config/eagle-eye.php` (the package does not interpret custom driver settings) and select it with `EAGLE_EYE_DRIVER=clickhouse`.

Production drivers should:

- treat repeated writes of the same entry id as idempotent,
- persist every field of `ActivityEntry::toStorage()` and restore it losslessly with `ActivityEntry::fromStorage()`,
- report per-entry rejections as `WriteFailure` values instead of throwing for the whole batch,
- order cursors by `logged_at` then `id`,
- apply limits in the backend rather than loading everything into memory,
- report capabilities honestly through `supports()`,
- make `install()` idempotent.

### Conformance tests

Run the shared suite against your driver in your Pest tests:

```php
use App\ActivityLog\ClickhouseDriver;
use Dotswan\EagleEye\Testing\DriverContractTests;

DriverContractTests::define('clickhouse', fn () => app(ClickhouseDriver::class));
```

It checks lossless round trips, idempotency, batching, filters, cursor pagination, traces, counts, aggregation, pruning, subject erasure, and installation. The shipped `database`, `memory`, and `loki` drivers run the same suite.

See [Extending](extending.md) for other extension points.
