# Queries

Read activity through `eagleEye()->query()` (an immutable `ActivityTrail`), the
`activityTrail()` Eloquent builder macro, or `ActivityRepositoryInterface` with an
`ActivityQuery`. All reads go through the active driver's capabilities; see
[drivers](drivers.md).

## Fluent trail

`eagleEye()->query()` (also `EagleEye::query()`) returns an
`Dotswan\EagleEye\Repository\ActivityTrail`. Every method returns a new trail, so a
reusable base query is never modified.

```php
$entries = eagleEye()->query()
    ->forActor('user', 8821)
    ->withAnyTag(['billing'])
    ->last('30 days')
    ->limit(100)
    ->get();               // QueryResult
```

| Method | Effect |
|---|---|
| `forActor(string $type, string\|int $id)` | actor type and ID |
| `forSubject(string $type, string\|int $id)` | subject type (or model class) and ID |
| `forSubjectClass(?string $className)` | subject model class |
| `forTrace(string $traceId)` | one trace |
| `channels(ActivityChannel ...$channels)` | any of the channels |
| `withAnyTag(array $tags)` | entries carrying at least one tag (exact match) |
| `search(string $term)` | full-text term |
| `whereStatus(string $operator, int $status)` | HTTP status with `=`, `!=`, `>`, `>=`, `<`, `<=` |
| `between(DateTimeImmutable $from, DateTimeImmutable $to)` | half-open `[from, to)` range; `from` must precede `to` |
| `last(string $period, ?DateTimeImmutable $now = null)` | `[now - period, now)`, e.g. `'24 hours'` |
| `orderByTime(SortOrder\|string $direction)` | `'desc'` (default) or `'asc'` |
| `limit(int $limit)` | page size 1–500 (default 50) |
| `cursor(?Cursor $cursor)` | continue from a previous page |
| `get()` | `QueryResult` |
| `count()` | number of matching entries |
| `aggregate(string $groupBy, string $interval = 'day')` | `array<string, int>` counts |

Action and severity filters exist on `ActivityQuery` only. Pass a prepared query to
`query()`:

```php
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivitySeverity;

$failures = eagleEye()->query(
    (new ActivityQuery())
        ->actions('model.updated', 'model.deleted')
        ->severities(ActivitySeverity::Error, ActivitySeverity::Critical),
)->last('7 days')->get();
```

`actions()` matches exact action values, never prefixes. It accepts at most 20
actions of `[A-Za-z0-9_.:-]`, each up to 191 bytes. Unlike the trail, `ActivityQuery`
setters modify and return the same instance.

The `activityTrail()` Eloquent builder macro starts a trail for a persisted model
(calls on a model are forwarded to its builder):

```php
$order->activityTrail()->last('90 days')->get();   // the order as subject (type and class)
$user->activityTrail('actor')->limit(20)->get();   // the user as actor
```

## Results and pagination

`QueryResult` exposes `entries` (`list<ActivityEntry>`), `nextCursor` (`?Cursor`),
`hasMore`, `count` (`?int`), and `exactCount`.

```php
$page = eagleEye()->query()->forSubject('order', 77213)->limit(50)->get();

while ($page->nextCursor !== null) {
    $page = eagleEye()->query()->forSubject('order', 77213)->limit(50)->cursor($page->nextCursor)->get();
}
```

`Cursor::encode()` produces the opaque string the [API](api.md) returns as
`links.next`; `Cursor::decode()` reverses it. Database-backed reads order by
`(logged_at, id)`. Loki reads order by `(Loki line timestamp, id)`, which in collector
mode is ingestion time. Loki queries without a range cover the last
`read.default_range_days` (30) days; use the SQL `index` accelerator for unbounded
actor or subject histories and exact counts (see [Loki](loki.md)).

## Repository

`Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface`:

```php
public function find(string $id): ?ActivityEntry;
public function search(ActivityQuery $query): QueryResult;
public function count(ActivityQuery $query): int;
public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array;
public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array;
public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array;
public function tags(?string $prefix = null, int $limit = 50): array;
public function reveal(ActivityEntry $entry, string $path, ?object $viewer = null): string;
```

Repository reads do not themselves produce activity entries. Check
`$driver->supports(Capability::…)` on
`app(ActivityLogDriverFactoryInterface::class)->driver()` before using optional
features.

### Ranked aggregation

When the driver supports `Capability::RankedAggregation`, call
`aggregateTop($query, $groupBy, $limit)`. The limit is clamped to 1–100 and the result
is ordered by count. SQL drivers apply `ORDER BY` and `LIMIT` in the database. Loki
requests at most 1,001 candidates and sorts by count, then key; if the candidate set
is full and the requested cutoff is tied, it throws a `LengthException` rather than
return an arbitrary ranking.

Ordinary `aggregate()` calls reject more than 1,000 groups.

### Series aggregation

When the driver supports `Capability::SeriesAggregation`, call
`aggregateSeries($query, $groupBy, $stepSeconds)`:

- the query must have both `from` and `to`; the range is aligned outward to the step;
- allowed steps are `300`, `3600`, `21600`, and `86400` seconds;
- at most 400 buckets and 5,000 points (buckets × groups) are returned;
- the result is `['buckets' => list<DateTimeImmutable>, 'series' => array<string, list<int>>]`,
  zero-filled and in bucket order, keyed `_total` when `$groupBy` is `null`;
- `logged_at`, `id`, `correlation_id`, and `causation_id` are not valid group keys.

Drivers that do not implement `ActivityLogSeriesDriverInterface` make
`aggregateSeries()` throw a `LogicException`.

### Tags

`tags($prefix, $limit)` returns distinct tags. SQL drivers return at most 500 values
and 64 KiB; the prefix match is case-sensitive on SQL drivers and case-insensitive on
Loki, which scans recent entries within its default window.

## Size limits

Every driver write admits a complete entry or rejects it before persistence: payload
64 KiB, complete envelope 128 KiB, strings 8 KiB (with smaller per-column limits), 50
tags, 50 encrypted values, and batches of at most 100 entries and 1 MiB. See
[performance](performance.md).
