# Extending Eagle Eye

The interfaces under `Dotswan\EagleEye\Contracts` are the public extension API. There
are four ways to extend the package:

- **Container bindings** — rebind a contract in an application service provider's
  `register()` method. Application providers register after package providers, so your
  binding wins. Use `$this->app->extend()` to decorate the default implementation.
- **Container tags** — `eagle-eye.title_resolvers`, `eagle-eye.enrichers`, and
  `eagle-eye.guzzle_middleware`.
- **Configuration lists** — `pipeline`, `filters.rules`, and `capture.*.via` in
  `config/eagle-eye.php`.
- **Drivers** — `ActivityLogDriverFactoryInterface::extend()`.

Bind replacements before Eagle Eye first records or reads activity; most defaults are
singletons that capture their dependencies when first resolved.

## Replaceable bindings

| Contract | Default | Used for |
|---|---|---|
| `Resolution\ActorResolverInterface` | `AuthActorResolver` | the actor of each entry |
| `Resolution\SubjectResolverInterface` | `ConventionSubjectResolver` | subject type, ID, label, and URL for models, notifications, and `activityTrail()` |
| `Resolution\FieldLabelResolverInterface` | `AttributeFieldLabelResolver` | `changes[].label` in the API envelope |
| `Resolution\ActivityIdentityResolverInterface` | `StoredActivityIdentityResolver` | read-time actor and subject display values |
| `Resolution\ActivityEntryPresenterInterface` | `ActivityEntryPresenter` | prepares API result pages (calls the identity resolver) |
| `Resolution\PayloadNormalizerInterface` | `PayloadNormalizer` | converts payload values to bounded, serializable data |
| `Title\TitleResolverInterface` | `TitleResolverChain` | the entry title |
| `Title\TemplateRendererInterface` | `PlaceholderTemplateRenderer` | renders translated title templates |
| `Sampling\SamplerInterface` | `DeterministicSampler` | `sampling.*` decisions |
| `Security\LogAccessPolicyInterface` | `GateAccessPolicy` | view and reveal authorization |
| `Security\CipherInterface` | `LaravelCipher` | `Encrypt` mode and reveal |
| `Security\BlindIndexInterface` | `HmacBlindIndex` | `Hash` mode and blind indexes of encrypted values |
| `Security\DurableRevealAuditInterface` | `DurableRevealAudit` | the audit entry written before every reveal |
| `Repository\ActivityRepositoryInterface` | `ActivityRepository` | every read: `query()`, the API, console commands |
| `Transport\TransportInterface` | `GuardedTransport` around the configured strategy | delivering flushed batches |
| `Correlation\TracePropagatorInterface` | `W3CTracePropagator` | reading and writing trace headers |
| `Integrity\IntegrityHasherInterface` | `CanonicalEntryHasher` | integrity chain hashes |
| `Privacy\SubjectEraserInterface` | `SubjectEraser` | `activity-log:forget` |

All contract names above are relative to `Dotswan\EagleEye\Contracts`.

## Drivers

Implement `Contracts\Driver\FullActivityLogDriverInterface` (write, query, and
maintenance methods) and register it under a name:

```php
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;

public function boot(): void
{
    $this->app->make(ActivityLogDriverFactoryInterface::class)
        ->extend('archive', fn () => $this->app->make(ArchiveDriver::class));
}
```

Select it with `EAGLE_EYE_DRIVER=archive`. The driver name passed to `extend()` is the
value of `eagle-eye.default`.

Prove conformance with the shared Pest contract suite:

```php
use Dotswan\EagleEye\Testing\DriverContractTests;

DriverContractTests::define('archive', fn () => new ArchiveDriver());
```

`php artisan activity-log:make-driver Archive [--path=…]` scaffolds
`app/ActivityLog/Drivers/ArchiveDriver.php`, `app/ActivityLog/Config/archive.php`, and
`app/ActivityLog/Tests/ArchiveDriverContractTest.php` (or the same layout under
`--path`). You still register the driver with `extend()` as shown above.

Optional interfaces:

- `ActivityLogSeriesDriverInterface` with `Capability::SeriesAggregation` serves
  `aggregateSeries()`. Add `DriverContractTests::defineSeries('archive', …)` to test
  alignment, zero-fill, and grouping. Without it, `aggregateSeries()` throws a
  `LogicException`.
- `StoresFullActivityBodiesInterface` marks a driver whose successful write preserves
  the complete entry body.
- `DurableSecurityAuditDriverInterface` lets the reveal audit refuse to run while the
  driver's connection has an open transaction.

Advertise features through `supports(Capability $capability)`; the API reports them in
`meta.capabilities`. See [drivers](drivers.md).

## Capture adapters

Each built-in capture is a `Contracts\Capture\CaptureAdapterInterface` whose
`register()` runs at boot. Setting `capture.<channel>.via` to your own class replaces
the built-in adapter for `model`, `auth`, `queue`, `console`, `mail`,
`notification`, `outbound_http`, `exception`, or `query`. (`capture.http.via` must
remain an `HttpCapture` instance.)

For an additional event source, call the recorder from your own listener:

```php
use Illuminate\Support\Facades\Event;

Event::listen(InvoicePaid::class, fn (InvoicePaid $event) => eagleEye()->record('invoice.paid', ['invoice_id' => $event->invoiceId]));
```

## Pipeline pipes

The `pipeline` configuration list is the ordered set of
`Contracts\Pipeline\ActivityPipeInterface` classes every draft passes through.
Pipes are resolved from the container:

```php
use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final class AttachDeployment implements ActivityPipeInterface
{
    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $draft->summary ??= 'release '.config('app.version');

        return $next($draft);
    }
}
```

Return `null` instead of calling `$next` to drop the entry. Insert the class in the
published `pipeline` list at the position you need. Pipes placed before
`RedactSensitive` have their payload redacted; pipes after `EnforceBudget` must not
grow the entry.

## Draft enrichers

For payload additions, a tagged `Contracts\Pipeline\DraftEnricherInterface` is simpler
than a pipe:

```php
use Dotswan\EagleEye\Contracts\Pipeline\DraftEnricherInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final class TeamEnricher implements DraftEnricherInterface
{
    public function __construct(private TeamResolver $teams)
    {
    }

    public function enrich(ActivityDraft $draft): void
    {
        $draft->payload['team'] = $this->teams->current()?->slug;
        $draft->tags->add('team:'.($this->teams->current()?->slug ?? 'none'));
    }
}
```

```php
$this->app->tag([TeamEnricher::class], 'eagle-eye.enrichers');
```

Enrichers run in the `RunEnrichers` pipe, which must stay in the `pipeline` list (by
default it follows `ProjectRequestParts`). They run before `RedactSensitive` and
`AttachTags`, so added keys are redacted and added tags respect `tags.max`.

## Tags

The built-in tag providers (`StaticTagProvider`, `AutomaticTagProvider`,
`AttributeTagProvider`) implement `Contracts\Tagging\TagProviderInterface` and are
applied by the `AttachTags` pipe; there is no registry for additional providers. Add
dynamic tags from a draft enricher (above), add constant tags with `tags.static`, or
wrap code in `eagleEye()->withTags([...], fn () => …)`.

## Filter rules

`filters.rules` accepts `Contracts\Filter\FilterRuleInterface` class names (resolved
from the container) and closures. Prefer class names; closures prevent
`config:cache`.

```php
use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;

final class IgnoreServiceAccounts implements FilterRuleInterface
{
    public function decide(ActivityDraft $draft): FilterDecision
    {
        return 'service' === $draft->actor?->type ? FilterDecision::Deny : FilterDecision::Abstain;
    }
}
```

Custom rules run after the built-in rules. Any `Deny` drops the entry; otherwise any
`Allow` keeps it, and if every rule abstains `filters.default_action` decides. See
[filtering](filtering.md).

## Title resolvers

Tag a `Contracts\Title\TitleResolverInterface` to title your own actions. Tagged
resolvers run before the built-in resolvers, so they can also override package
titles:

```php
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final class RefundTitleResolver implements TitleResolverInterface
{
    public function resolve(ActivityDraft $draft): ?string
    {
        return 'refund.approved' === $draft->action ? 'Refund approved' : null;
    }
}
```

```php
$this->app->tag([RefundTitleResolver::class], 'eagle-eye.title_resolvers');
```

Return `null` to fall through to the next resolver. To replace the whole chain, bind
`TitleResolverInterface` instead; when it returns `null` or an empty string, the
draft keeps its current title (the action name by default). Titles are stored at
capture time and run after redaction, so do not copy personal data into them.

## Template renderer

Built-in titles come from the `eagle-eye::titles` translation file (publish it with
`--tag=eagle-eye-lang`). The renderer receives the already translated template and
its `:placeholder` values:

```php
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;

final class PlainTemplateRenderer implements TemplateRendererInterface
{
    public function render(string $template, array $data): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            $replacements[':'.$key] = (string) $value;
        }

        return trim(strtr($template, $replacements));
    }
}
```

The default renderer also removes placeholders without a value and tidies the
surrounding punctuation.

## Actor resolver

```php
use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Data\Actor;

final class ApiClientActorResolver implements ActorResolverInterface
{
    public function __construct(private AuthActorResolver $users, private ClientContext $clients)
    {
    }

    public function resolve(): ?Actor
    {
        $client = $this->clients->current();

        return null !== $client ? new Actor('api_client', (string) $client->id) : $this->users->resolve();
    }
}
```

Bind it with `$this->app->scoped(ActorResolverInterface::class, ApiClientActorResolver::class)`.
`Actor` is `new Actor(string $type, ?string $id = null, ?string $label = null, ?string $url = null)`.
Labels are removed before storage unless `privacy.actor_label` is `keep`.

## Subject resolver

Decorate the default to map specific objects:

```php
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\Subject;

final class InvoiceSubjectResolver implements SubjectResolverInterface
{
    public function __construct(private SubjectResolverInterface $fallback)
    {
    }

    public function resolve(object $subject): Subject
    {
        return $subject instanceof Invoice
            ? new Subject('invoice', (string) $subject->number, $subject->reference, Invoice::class)
            : $this->fallback->resolve($subject);
    }
}
```

```php
$this->app->extend(SubjectResolverInterface::class, fn (SubjectResolverInterface $default) => new InvoiceSubjectResolver($default));
```

`Subject` is `new Subject(string $type, string $id, ?string $label = null, ?string $className = null, ?string $url = null)`.

## Field-label resolver

```php
public function resolve(object|string $subject, string $field): string
{
    return ['vat_id' => 'VAT number'][$field] ?? Str::headline($field);
}
```

`$subject` is the subject class name when known, otherwise its type. Bind
`FieldLabelResolverInterface`.

## Read-time identity resolver

Implement `ActivityIdentityResolverInterface` to show display names that are not
stored (see [privacy](privacy.md#actor-labels)). `resolveActors()` and
`resolveSubjects()` receive `Actor` / `Subject` values keyed by activity entry ID and
must return values under the same keys. Resolve each page with one bounded query.
Missing identities may return the input unchanged.

```php
public function resolveActors(array $actors): array
{
    $ids = array_map(fn (Actor $actor) => $actor->id, array_filter($actors, fn (Actor $actor) => 'user' === $actor->type));
    $names = User::query()->whereKey(array_unique($ids))->pluck('name', 'id');

    return array_map(
        fn (Actor $actor) => 'user' === $actor->type && isset($names[$actor->id])
            ? new Actor($actor->type, $actor->id, $names[$actor->id], $actor->url)
            : $actor,
        $actors,
    );
}
```

The API calls the resolver once per result page through
`ActivityEntryPresenterInterface`. A separate UI package can use the same
`ActivityRepositoryInterface` and presenter.

## Payload normalizer

Decorate the default so recursion and size bounds stay intact:

```php
public function __construct(private PayloadNormalizerInterface $inner)
{
}

public function normalize(mixed $value): mixed
{
    if ($value instanceof Money) {
        return ['amount' => $value->amount, 'currency' => $value->currency];
    }

    return $this->inner->normalize($value);
}
```

Register it with `$this->app->extend(PayloadNormalizerInterface::class, fn ($default) => new MoneyAwareNormalizer($default))`.
The default only calls the inner normalizer for the top-level value; nested `Money`
objects are handled by the default rules (for example `JsonSerializable`).

## Sampler

```php
use Dotswan\EagleEye\Contracts\Sampling\SamplerInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class SecurityFirstSampler implements SamplerInterface
{
    public function passes(ActivityDraft $draft): bool
    {
        if (ActivityChannel::Auth === $draft->channel) {
            return true;
        }

        return crc32($draft->trace?->traceId ?? $draft->id) % 10 === 0;
    }
}
```

Bind `SamplerInterface`. Deciding by trace ID keeps whole traces together.

## Redaction strategies

The modes in `RedactionMode` are implemented by `Contracts\Redaction\RedactionStrategyInterface`
classes that the service provider wires into `Dotswan\EagleEye\Redaction\Redactor`;
there is no configuration key for additional strategies. To change how values are
hashed or encrypted, replace `BlindIndexInterface` or `CipherInterface` (below). Choose
modes per key with `privacy.paths`, key patterns, `#[ActivityField]`, and
`DeclaresActivityPrivacy` (see [privacy](privacy.md)).

## Cipher

```php
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Data\SecretRef;

final class HsmCipher implements CipherInterface
{
    public function __construct(private HsmClient $hsm)
    {
    }

    public function encrypt(string $plaintext): SecretRef
    {
        return new SecretRef(
            algorithm: 'hsm:aes-256-gcm',
            keyFingerprint: 'hsm-v2',
            ciphertext: $this->hsm->encrypt('hsm-v2', $plaintext),
        );
    }

    public function decrypt(SecretRef $secret): string
    {
        return $this->hsm->decrypt($secret->keyFingerprint, $secret->ciphertext);
    }
}
```

Bind `CipherInterface`. The `blindIndex` field of `SecretRef` is filled by the
encryption strategy.

## Blind index

```php
public function hash(string $value): string
{
    return hash_hmac('sha256', mb_strtolower($value), config('services.audit.blind_index_key'));
}
```

Bind `BlindIndexInterface`. Changing the function changes every new hash; plan an
explicit migration window.

## Access policy

```php
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Data\ActivityEntry;

final class AuditAccessPolicy implements LogAccessPolicyInterface
{
    public function canView(?object $viewer = null): bool
    {
        return $viewer instanceof User && $viewer->can('audit.view');
    }

    public function canReveal(ActivityEntry $entry, string $path, ?object $viewer = null): bool
    {
        return $this->canView($viewer) && $viewer->can('audit.reveal');
    }
}
```

Bind `LogAccessPolicyInterface` before enabling the [API](api.md).

## Repository

Decorate `ActivityRepositoryInterface` to scope or meter reads. Delegate every method:

```php
public function search(ActivityQuery $query): QueryResult
{
    $scoped = clone $query;

    if ([] === $scoped->channels) {
        $scoped->channels(...$this->visibleChannels());
    }

    return $this->inner->search($scoped);
}
```

```php
$this->app->extend(ActivityRepositoryInterface::class, fn (ActivityRepositoryInterface $inner) => new ScopedActivityRepository($inner));
```

`ActivityQuery` setters mutate the instance, so clone queries you did not create.

## Transport

```php
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;

final class BusTransport implements TransportInterface
{
    public function send(array $entries): void
    {
        AuditBatch::dispatch(array_map(fn (ActivityEntry $entry) => $entry->toStorage(), $entries));
    }
}
```

Binding `TransportInterface` replaces the whole default transport, including the
spool fallback (`transport.on_failure`) and the erasure-barrier check that
`GuardedTransport` performs. Keep equivalent failure handling and bounded batches
(at most 100 entries). See [performance](performance.md).

## Trace propagator

```php
use Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface;
use Dotswan\EagleEye\Data\TraceContext;

final class AuditHeaderPropagator implements TracePropagatorInterface
{
    public function __construct(private W3CTracePropagator $w3c)
    {
    }

    public function extract(array $carrier): TraceContext
    {
        return $this->w3c->extract($carrier);
    }

    public function inject(TraceContext $context, array $carrier): array
    {
        $carrier = $this->w3c->inject($context, $carrier);
        $carrier['x-audit-trace'] = $context->traceId;

        return $carrier;
    }
}
```

Bind `TracePropagatorInterface`. It is used for incoming HTTP requests, outbound HTTP,
and queued jobs. See [correlation](correlation.md).

## Integrity hasher

```php
use Dotswan\EagleEye\Contracts\Integrity\IntegrityHasherInterface;
use Dotswan\EagleEye\Data\ActivityEntry;

final class KeyedEntryHasher implements IntegrityHasherInterface
{
    public function hash(ActivityEntry $entry, ?string $previousHash = null): string
    {
        $storage = $entry->toStorage();
        $storage['integrity_hash'] = null;

        return hash_hmac('sha256', ($previousHash ?? '').json_encode($this->sorted($storage), JSON_THROW_ON_ERROR), config('services.audit.integrity_key'));
    }

    private function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sorted($item) : $item, $value);
    }
}
```

Bind `IntegrityHasherInterface`. Clear `integrity_hash` and sort nested maps before
hashing so verification is stable. Entries sealed with a previous hasher no longer
verify after the change.

## Capturing SDKs that use their own Guzzle client

SDKs that build their own Guzzle client (for example Mollie and some SMS providers) do
not dispatch Laravel's HTTP client events. When Guzzle is installed, Eagle Eye tags
its middleware as `eagle-eye.guzzle_middleware`; push it onto the client's
`HandlerStack`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();

foreach (app()->tagged('eagle-eye.guzzle_middleware') as $middleware) {
    $stack->push($middleware);
}

$client = new Client(['handler' => $stack]);
```

The middleware records `outbound_http.response` and `outbound_http.failed` entries
with the same rules as Laravel HTTP client capture: `capture.outbound_http.enabled`,
`except_hosts`, and suppression while Eagle Eye performs its own I/O.
