# Privacy, redaction, and erasure

This page describes what Eagle Eye captures, how values are redacted before storage,
and how encrypted values, erasure, retention, and integrity work. Configuration keys
are under `privacy`, `retention`, and `integrity` in `config/eagle-eye.php`; see
[configuration](configuration.md).

## Capture inventory

| Channel | Captured by default | Excluded or minimised |
|---|---|---|
| HTTP | method, URL, route, status, duration, truncated IP, user agent, request parts from `capture.http.parts` (default `query`, `route_params`) | cookies and session are withheld by `filters.request_parts.except`; request body and file metadata only for `capture.http.body_routes`; response body only for error statuses (`capture.http.response.body = 'errors_only'`), bounded by `max_bytes` |
| Model | event, subject identity, changed attributes | unchanged attributes; `capture.model.ignored_change_fields` |
| Auth | lifecycle action and actor identity | actor display label (see [Actor labels](#actor-labels)) |
| Queue | job class, connection, queue, job UUID, attempts, duration, exception class and message on failure | serialized job payload |
| Console / Schedule | command, arguments, options, exit code, duration | commands in `capture.console.except`; secrets in arguments/options must match a redaction rule |
| Mail | subject, recipient counts and domains, masked recipients (`capture.mail.store_recipients`, `max_recipients`), mailer, message ID | message body |
| Notification | lifecycle and notification type | rendered content |
| Outbound HTTP | method, host, path, query parameter names, status, duration, response size; response body up to `capture.outbound_http.error_body_max_bytes` on error statuses | request body, headers, query values |
| Exception | class, message, file, line (deduplicated) | stack trace |
| Query | slow-query SQL text, duration, connection, binding count — only when `capture.query.enabled` | binding values; disabled by default |
| Custom | the action and payload passed to `record()` | — |

Every payload passes through the same pipeline: normalisation, redaction, encryption,
and size budgets (see [performance](performance.md)). Exception and error messages,
titles, summaries, subject labels, and URLs are free text: redaction rules match
**keys**, not arbitrary text, so keep personal data out of messages or remove it
before capture.

## Redaction modes

`Dotswan\EagleEye\Enums\RedactionMode`:

| Mode | Result |
|---|---|
| `Keep` (`keep`) | value stored unchanged |
| `Mask` (`mask`) | characters replaced with `privacy.mask.char`, keeping `keep_prefix` / `keep_suffix` characters (defaults `*`, 0, 0) |
| `Partial` (`partial`) | shape-preserving mask: emails become `q***@domain`, phone numbers keep a short hint, other text keeps the first character of each word |
| `Hash` (`hash`) | HMAC-SHA256 via `BlindIndexInterface` (keyed with `app.key` by default) |
| `Encrypt` (`encrypt`) | value encrypted with `CipherInterface` and stored in the entry's `secrets`; the payload keeps `{"__secret": "payload.<path>"}` |
| `Drop` (`drop`) | key removed from the payload |

For model change pairs (`{"old": …, "new": …}`), `Mask`, `Partial`, and `Hash` are
applied to both sides separately.

## Choosing a mode: precedence

For every key in the payload, at any depth, `Dotswan\EagleEye\Redaction\Redactor`
takes the **first** rule that matches:

1. **Path rules** — `privacy.paths`, a map of dot-path pattern to mode, checked in
   configuration order. Paths are relative to the payload root. `*` matches one
   segment and `**` matches any number of segments:

   ```php
   'paths' => [
       'account.iban'     => 'encrypt',
       'customer.*.phone' => 'partial',
       '**.notes'         => 'drop',
   ],
   ```

2. **Declared field modes** — keyed by attribute name and matched against the key at
   any depth:
   - `DeclaresActivityPrivacy::activityPrivacy()` on the model instance (resolved once
     per model instance, without database queries; the implementation must use loaded
     attributes only), which overrides
   - `#[ActivityField(redact: RedactionMode::…)]` on model properties. `Keep` is the
     attribute's default and is therefore not treated as a declaration; declare `Keep`
     through `DeclaresActivityPrivacy` instead.
3. **`privacy.sensitive_key_patterns`** — case-insensitive wildcard patterns
   (default `*password*`, `*token*`, `*secret*`, `*api_key*`, `*authorization*`,
   `*otp*`, `*iban*`, `*card*`, `*cvv*`). Matching keys get `privacy.default_mode`
   (default `mask`).
4. **`privacy.partial_key_patterns`** — case-insensitive wildcard patterns (default
   `*email*`, `*phone*`, `*mobile*`, `*first_name*`, `*last_name*`, `*full_name*`,
   `*recipient*`, `username`). Matching keys get `Partial`.
5. **Email value detection** — when `privacy.detect_email_values` is true and no rule
   above matched, any string value that is a valid email address gets `Partial`.

A key that matched a rule, including `Keep`, is never re-evaluated by email detection.
A field explicitly declared `Keep` also disables email-value detection for every value
nested beneath it; path and key rules still apply to nested keys.

```php
use Dotswan\EagleEye\Contracts\DeclaresActivityPrivacy;
use Dotswan\EagleEye\Enums\RedactionMode;

final class Customer extends Model implements DeclaresActivityPrivacy
{
    public function activityPrivacy(): array
    {
        return [
            'support_email' => RedactionMode::Keep,     // shared mailbox, not personal data
            'iban'          => RedactionMode::Encrypt,
            'birth_date'    => $this->is_minor ? RedactionMode::Drop : RedactionMode::Hash,
        ];
    }
}
```

Redaction runs in two pipeline steps: `RedactSensitive` applies `Mask`, `Partial`,
`Hash`, and `Drop`; `EncryptSensitive` then applies `Encrypt`. Draft enrichers run
before both, so keys they add are redacted too (see [extending](extending.md)).

The same key rules are applied to query-string values in request, actor, and subject
URLs. Subject labels are never read from an attribute whose mode is anything other
than `Keep`.

## IP addresses

With `privacy.ip.mode = 'truncate'` (default), request IPs are truncated to
`privacy.ip.ipv4_bits` (default 24) and `privacy.ip.ipv6_bits` (default 48) prefix
bits, for example `203.0.113.57` → `203.0.113.0`. Unparseable addresses are stored as
`null`. Any other mode value stores the IP unchanged.

## Actor labels

`privacy.actor_label` defaults to `resolve_at_read`: actor display labels are removed
before storage, leaving only the actor type and ID. Set it to `keep` to store labels.

To show names at read time, bind `ActivityIdentityResolverInterface`. The API calls it
once per result page with the page's actors and subjects keyed by entry ID; the
default `StoredActivityIdentityResolver` returns stored values unchanged. See
[extending](extending.md).

## Revealing encrypted values

Encrypted values are listed in the entry's `sealed` paths and can be decrypted with
`ActivityRepositoryInterface::reveal($entry, $path, $viewer)` or the
`POST activity-logs/{id}/reveal` endpoint (see [API](api.md)).

1. `LogAccessPolicyInterface::canReveal()` must allow the viewer; otherwise
   `SecretRevealDeniedException` is thrown.
2. An `eagle-eye.secret.revealed` audit entry (channel `custom`, tags `security`,
   `audit`) is written synchronously to the audit driver. It is written even when
   ordinary capture is disabled, filtered, or sampled out.
3. Only after the audit write succeeds is the value decrypted and returned.

The audit driver is `privacy.reveal_audit_driver`, or the default driver when it is
`null`. In production it must be set to `database`; any other value blocks
decryption. Decryption is also refused while the audit connection has an open
transaction, so a caller's rollback cannot remove the audit entry after plaintext was
returned.

## Erasure

Erase one data subject with:

```bash
php artisan activity-log:forget {type} {id}
```

`{type}` is the subject or actor type (or model class) and `{id}` its identifier.
Entries are removed where the subject or the actor matches.

1. An erasure barrier — a keyed HMAC of the identity — and a request receipt are
   committed on `privacy.erasure_connection` (default connection when `null`).
2. While the barrier exists (`privacy.erasure_barrier_days`, default 31), new entries
   referencing that identity are discarded before delivery, including entries replayed
   from the transport spool or the queue.
3. Deliveries to a backend other than the erasure connection record a pending delivery
   attempt (an identity HMAC) before I/O and clear it once the backend acknowledges the
   write. Erasure refuses to run while a matching attempt is unresolved.
4. The driver's `forget()` runs and the receipt records the outcome. A
   `privacy.subject.forgotten` or `privacy.subject.erasure_requested` entry is recorded
   with the subject type, an HMAC digest, the request ID, the removed count, and the
   outcome — never the subject ID or label.

Outcomes:

- SQL and memory drivers return `completed` with the number of removed entries.
- Loki returns `accepted_pending` with the compactor request IDs for the subject and
  actor queries. Monitor them until `processed`; physical removal follows compaction.
  With the Loki stdout write path, erasure is not available (see [Loki](loki.md)).
- Composite results that are partly or entirely failed make the command fail.

If an unresolved delivery attempt blocks erasure, confirm independently that the
remote side can no longer ingest it, then resolve it:

```bash
php artisan activity-log:forget user 42 \
    --resolve-attempt=01J… \
    --settlement-evidence="Loki push 01J… confirmed absent after compaction"
```

The attempt ULID and identity must match, and the evidence (16–2,048 bytes) is
retained only as a SHA-256 digest.

Expired barriers are not removed automatically. Schedule
`Dotswan\EagleEye\Privacy\ErasureRegistry::purgeExpired($limit = 1000)` according to
your retention policy. `privacy.erasure_max_barriers`, `erasure_max_receipts`, and
`delivery_max_attempts` (default 100,000 each) are hard quotas; when one is full, the
corresponding operation fails instead of silently dropping protection. Keep the barrier
lifetime longer than `transport.spool.max_age_days`.

## Retention

`php artisan activity-log:prune` deletes entries older than their retention window:

- `retention.severities` overrides `retention.channels`, which overrides
  `retention.default_days` (default 30; `auth` is kept 365 days by default).
- `retention.tags` may only shorten the window; a tag rule longer than the shortest
  base window is rejected.
- Unknown channel or severity keys and invalid values abort pruning before any
  deletion.

The Loki driver cannot prune; generate Loki retention configuration with
`activity-log:loki-config`, which rejects tag rules.

## Integrity

Integrity sealing is opt-in (`integrity.enabled`, default `false`) and applies to
`integrity.channels` (default `['auth']`). A database ledger on
`integrity.connection` groups entries into segments of `integrity.segment_size`
(default 1,000), records each entry's hash, order, and predecessor, and authenticates
segment anchors and entry lifecycle with the security key. Delivered, discarded,
erased, and pruned entries move through authenticated state transitions, so legitimate
retention and erasure remain verifiable.

`php artisan activity-log:verify` reports missing hashes, entries, or segments,
invalid anchors, changed metadata, and altered content. It reads at most 100 segments
and 500 entries per query and retains up to 1,000 failing IDs while reporting the full
failure count. These guarantees assume the security key and the integrity database
are trusted; anchor externally if a database administrator is inside your threat
model. Replace the hash function with `IntegrityHasherInterface` (see
[extending](extending.md)).

## Security key

Erasure HMACs, receipts, and integrity anchors use `EAGLE_EYE_SECURITY_KEY`
(`security_key`). Outside `local` and `testing` it must be at least 32 bytes; in those
two environments `app.key` is used when it is not set. Use an independent random
value, keep it identical across web, queue, and scheduler processes, and keep it
stable across application-key rotations. Eagle Eye stores the key's fingerprint and
fails closed if the configured key changes; there is no key rotation path, so restore
the original key after an accidental change.

`Hash` redaction and blind indexes use `app.key` through `HmacBlindIndex`; rotating
`app.key` changes those hashes.
