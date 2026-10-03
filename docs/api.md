# Retrieval API

Eagle Eye ships an opt-in, read-only JSON API. Every entry uses one versioned envelope
(`schema_version` 1). The TypeScript contract is
`resources/js/types/eagle-eye.d.ts`, and a test fails when the PHP resource and the
TypeScript types diverge.

## Enabling

```php
// config/eagle-eye.php
'api' => [
    'enabled'    => env('EAGLE_EYE_API_ENABLED', false),
    'prefix'     => env('EAGLE_EYE_API_PREFIX', 'api/v1'),
    'middleware' => ['api'],
],
```

Routes are registered only when `api.enabled` is true. The default middleware is
`['api']` and does **not** authenticate. Add your guard, for example
`['api', 'auth:sanctum']`, so that the access policy receives a user.

## Authorization

Every endpoint calls `Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface`:

```php
interface LogAccessPolicyInterface
{
    public function canView(?object $viewer = null): bool;

    public function canReveal(ActivityEntry $entry, string $path, ?object $viewer = null): bool;
}
```

The default binding, `Dotswan\EagleEye\Security\GateAccessPolicy`, checks two Gate
abilities. Neither is defined by the package, so every request is denied until you
define them:

| Ability | Arguments | Checked by |
|---|---|---|
| `viewActivityLog` | `($user)` | every endpoint |
| `revealActivityLog` | `($user, ActivityEntry $entry, string $path)` | `POST …/reveal`, in addition to `viewActivityLog` |

```php
use Dotswan\EagleEye\Data\ActivityEntry;
use Illuminate\Support\Facades\Gate;

Gate::define('viewActivityLog', fn (User $user): bool => $user->isAuditor());
Gate::define('revealActivityLog', fn (User $user, ActivityEntry $entry, string $path): bool => $user->isSecurityOfficer());
```

The viewer passed to the policy is `$request->user()`. To use different rules, bind
your own `LogAccessPolicyInterface` implementation (see [extending](extending.md)).

## Endpoints

All paths are relative to `api.prefix`.

| Method | Path | Route name | Purpose |
|---|---|---|---|
| `GET` | `activity-logs` | `eagle-eye.activity-logs.index` | filtered, cursor-paginated listing |
| `GET` | `activity-logs/{id}` | `eagle-eye.activity-logs.show` | one entry |
| `GET` | `activity-logs/traces/{traceId}` | `eagle-eye.activity-logs.trace` | one trace as nested causal trees |
| `GET` | `activity-logs/stats` | `eagle-eye.activity-logs.stats` | grouped counts |
| `GET` | `activity-logs/tags` | `eagle-eye.activity-logs.tags` | known tags |
| `POST` | `activity-logs/{id}/reveal` | `eagle-eye.activity-logs.reveal` | decrypt one sealed value |
| `GET` | `activity-logs/stream` | `eagle-eye.activity-logs.stream` | live tail placeholder, always `501` |

### `GET activity-logs`

| Parameter | Rules | Effect |
|---|---|---|
| `actor_type`, `actor_id` | both or neither; ≤ 64 chars each | `forActor()` |
| `subject_type`, `subject_id` | both or neither; ≤ 128 / ≤ 64 chars | `forSubject()`; `subject_type` may also be the model class name |
| `channel` | one `ActivityChannel` value (`http`, `model`, `auth`, `queue`, `console`, `mail`, `notification`, `outbound_http`, `exception`, `log`, `schedule`, `query`, `custom`) | `channels()` |
| `trace_id` | 32 lowercase hex characters | `forTrace()` |
| `tags` | comma-separated, ≤ 255 chars | `withAnyTag()` |
| `search` | ≤ 255 chars | full-text `search()` |
| `status` | optional operator (`=`, `!=`, `>`, `>=`, `<`, `<=`) followed by a 3-digit code, e.g. `>=500` | `whereStatus()` |
| `from`, `to` | both or neither; see [Dates](#dates) | `between()` |
| `cursor` | opaque value from `links.next`, ≤ 512 chars | next page |
| `per_page` | 1–100, default 50 | page size |
| `sort` | `asc` or `desc` (default) | time order |

Response:

```jsonc
{
  "data": [ /* entries, see "The envelope" */ ],
  "meta": {
    "driver": "database",
    "capabilities": ["full_text_search", "exact_count", "unbounded_time_range", "aggregation",
                     "ranked_aggregation", "series_aggregation", "synchronous_deletion"],
    "count": { "value": 2, "exact": true }
  },
  "links": { "next": "eyJ0aW1lc3RhbXAiOi…" }   // null on the last page
}
```

`meta.capabilities` lists the `Capability` values the active driver supports
(`live_tail` is never listed). On drivers with `exact_count`, `meta.count.value` is the
total number of matching entries. On drivers without it (Loki), `meta.count.exact` is
`false` and `value` is the number of entries on the current page.

Without `from` and `to`, the listing applies no time bound of its own; the Loki driver
then uses its default window (see [Loki](loki.md)).

### `GET activity-logs/{id}`

Returns `{ "data": <entry>, "meta": { "driver", "capabilities" } }`, or `404` when the
entry does not exist.

### `GET activity-logs/traces/{traceId}`

Returns the trace's entries in ascending time order, assembled into causal trees:

```jsonc
{
  "data": [{ "entry": { /* entry */ }, "children": [{ "entry": { … }, "children": [] }] }],
  "meta": {
    "driver": "database",
    "capabilities": [ … ],
    "trace": { "complete": false, "returned": 15, "next": "eyJ0…" }
  }
}
```

Each page holds at most 15 entries. Pass `meta.trace.next` back as `?cursor=` until it
is `null`; `complete` is then `true`. An invalid cursor returns `422`. `meta.trace` is
not part of the `EagleEyeMeta` TypeScript type; read it as an additional field.

### `GET activity-logs/stats`

| Parameter | Values | Default |
|---|---|---|
| `group_by` | `channel`, `action`, `severity`, `actor_type`, `subject_type`, `logged_at` | `channel` |
| `interval` | `hour`, `day`, `month` (used when grouping by `logged_at`) | `day` |
| `from`, `to` | both or neither; see [Dates](#dates) | the 366 days ending now |

Response (no `meta` block):

```json
{ "data": { "group_by": "channel", "interval": "day", "groups": { "http": 120, "model": 37 } } }
```

A grouping the active driver does not support returns `422` on `group_by`. The Loki
driver does not support `logged_at` buckets.

### `GET activity-logs/tags`

| Parameter | Rules | Default |
|---|---|---|
| `prefix` | tag prefix, ≤ 256 bytes (case-sensitive on SQL drivers, case-insensitive on Loki) | none |
| `limit` | 1–100 | 50 |

Returns `{ "data": ["billing", "gdpr"], "meta": { … } }`.

### `POST activity-logs/{id}/reveal`

Body (or query string): `{ "path": "payload.account.iban" }`. The path must be one of
the entry's `sealed` paths and match `[A-Za-z0-9_.-]+` (≤ 256 bytes).

Returns `{ "data": { "path": "payload.account.iban", "value": "…" }, "meta": { … } }`.

The request is checked with `canView()` and then `canReveal()`. Before decryption the
repository writes an `eagle-eye.secret.revealed` audit entry directly to the audit
driver; if that write is rejected, no plaintext is returned. In production the audit
driver must be configured as `privacy.reveal_audit_driver = 'database'`. See
[privacy](privacy.md#revealing-encrypted-values).

### `GET activity-logs/stream`

Live tailing is not implemented. After `canView()` passes, the endpoint answers `501`
with `{ "data": null, "meta": { "driver": "…", "capabilities": [], "reason": "…" } }`.

## Dates

`from` and `to` must be sent together. Each is either a calendar date (`Y-m-d`,
interpreted in `app.timezone`) or an RFC 3339 timestamp with `Z` or a valid offset
(`2026-01-31T12:00:00.000+02:00`). A date-only `to` means the following midnight, so
ranges are half-open `[from, to)`. `from` must precede `to`, and the range may not
exceed 366 days. Invalid dates return `422` before the backend is queried.

## Errors

| Status | When |
|---|---|
| `403` | `canView()` is false, or `canReveal()` is false on reveal |
| `404` | the entry for `show` or `reveal` does not exist |
| `422` | invalid parameters, invalid cursor, invalid date range, missing reveal `path`, or a stats grouping the driver does not support |
| `501` | `activity-logs/stream` |

On reveal, a `path` that is not sealed on the entry, or a reveal audit that cannot be
written (for example a missing `privacy.reveal_audit_driver` in production), raises an
exception and is reported through Laravel's exception handler (a `500` response by
default).

## The envelope

```jsonc
{
  "id": "01J…",
  "schema_version": 1,
  "logged_at": "2026-09-08T12:00:01.123456Z",
  "channel": "http",
  "action": "http.request",
  "severity": "info",
  "title": "GET /orders answered with 200",     // rendered at capture time
  "summary": null,
  "actor":   { "type": "user", "id": "8821", "label": null, "url": null },
  "subject": { "type": "order", "id": "77213", "label": "Order 77213", "class": "App\\Models\\Order", "url": "/orders/77213" },
  "trace":   { "trace_id": "…", "correlation_id": "…", "causation_id": null, "parent_span_id": null },
  "tags": ["shop", "gdpr"],
  "request": { "method": "GET", "url": "/orders", "route": "orders.index", "status_code": 200, "duration_ms": 42, "ip": "10.0.0.0", "user_agent": "…" },
  "changes": [{ "path": "status", "label": "Status", "before": "draft", "after": "approved" }],
  "payload": { /* full redacted payload */ },
  "sealed": ["payload.account.iban"],           // encrypted paths, revealable via POST …/reveal
  "payload_bytes": 128,
  "environment": "production",
  "hostname": "web-01",
  "memory_kb": null,
  "integrity_hash": null,
  "created_at": "2026-09-08T12:00:01.123456Z"
}
```

- `changes` is derived from `payload.changes`, with labels from
  `FieldLabelResolverInterface`.
- Actor and subject identities pass through `ActivityIdentityResolverInterface` once per
  response page, so labels removed at capture (`privacy.actor_label`) can be resolved
  at read time.
- The same envelope is produced by every driver.

## Consuming from TypeScript

Import the types from `resources/js/types/eagle-eye.d.ts` (copy the file into your
frontend or reference it from `vendor/`).

### Vue

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import type { EagleEyeActivityEntry, EagleEyeListResponse } from './types/eagle-eye';

const entries = ref<EagleEyeActivityEntry[]>([]);
const next = ref<string | null>(null);

async function load(cursor?: string): Promise<void> {
    const query = new URLSearchParams({ per_page: '50' });
    if (cursor) query.set('cursor', cursor);

    const response = await fetch(`/api/v1/activity-logs?${query}`, {
        headers: { Accept: 'application/json' },
    });
    const body: EagleEyeListResponse = await response.json();

    entries.value.push(...body.data);
    next.value = body.links.next;
}

onMounted(() => load());
</script>

<template>
    <ul>
        <li v-for="entry in entries" :key="entry.id">
            <strong>{{ entry.title }}</strong>
            <span>{{ entry.actor?.label ?? 'system' }} · {{ entry.logged_at }}</span>
            <dl v-for="change in entry.changes" :key="change.path">
                <dt>{{ change.label }}</dt>
                <dd>{{ change.before }} → {{ change.after }}</dd>
            </dl>
        </li>
    </ul>
    <button v-if="next" @click="load(next)">Load more</button>
</template>
```

### React

```tsx
import { useEffect, useState } from 'react';
import type { EagleEyeActivityEntry, EagleEyeListResponse } from './types/eagle-eye';

export function ActivityLogList() {
    const [entries, setEntries] = useState<EagleEyeActivityEntry[]>([]);
    const [next, setNext] = useState<string | null>(null);

    async function load(cursor?: string) {
        const query = new URLSearchParams({ per_page: '50' });
        if (cursor) query.set('cursor', cursor);

        const body: EagleEyeListResponse = await fetch(`/api/v1/activity-logs?${query}`, {
            headers: { Accept: 'application/json' },
        }).then((r) => r.json());

        setEntries((current) => [...current, ...body.data]);
        setNext(body.links.next);
    }

    useEffect(() => { load(); }, []);

    return (
        <>
            <ul>
                {entries.map((entry) => (
                    <li key={entry.id}>
                        <strong>{entry.title}</strong>
                        <span>{entry.actor?.label ?? 'system'} · {entry.logged_at}</span>
                    </li>
                ))}
            </ul>
            {next && <button onClick={() => load(next)}>Load more</button>}
        </>
    );
}
```
