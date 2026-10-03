# Usage

Most activity is captured automatically (see [Configuration › Capture](configuration.md#capture)). This page covers recording your own domain events, describing models, reading activity back, and testing.

## Recording activities

### Facade or helper

```php
use Dotswan\EagleEye\Facades\EagleEye;

EagleEye::record('invoice.approved', ['invoice_id' => 42, 'amount' => 12000]);

// or
eagleEye()->record('invoice.approved', ['invoice_id' => 42]);
```

`record()` returns the immutable `ActivityEntry`, or `null` when nothing was recorded (disabled, environment not allowed, paused, filtered, sampled out, `#[DoNotLog]` subject, or an internal failure). It never throws into your code: failures are reported through Laravel's exception handler.

Action names are free-form; a `domain.verb` convention (`invoice.approved`) keeps filtering and statistics readable.

### Channel and severity

```php
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;

EagleEye::record('invoice.declined', ['invoice_id' => 42], ActivityChannel::Custom, ActivitySeverity::Warning);
```

Channels: `http`, `model`, `auth`, `queue`, `console`, `mail`, `notification`, `outbound_http`, `exception`, `log`, `schedule`, `query`, `custom` (default for manual entries).

Severities: `debug`, `info` (default), `notice`, `warning`, `error`, `critical`, `alert`, `emergency`.

### Full control with `ActivityDraft`

```php
use App\Models\Invoice;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Facades\EagleEye;

$draft = ActivityDraft::make('invoice.sent', ActivityChannel::Custom, ActivitySeverity::Info)
    ->withActor(new Actor(type: 'user', id: '8821', label: 'Operations User'))
    ->withSubject(new Subject(
        type: 'invoice',
        id: 'INV-1042',
        label: 'Invoice INV-1042',
        className: Invoice::class,
        url: '/invoices/INV-1042',
    ))
    ->withTags(['billing', 'tenant:42'])
    ->withPayload(['total' => 12000, 'currency' => 'EUR']);

$draft->title = 'Invoice sent';
$draft->summary = 'Invoice was delivered to the customer.';

EagleEye::record($draft);
```

When you omit the actor, the authenticated user is used (`user`), or `console` / `guest`. Ids, trace, timestamps, environment, and hostname are filled in automatically. To resolve a subject from a model, use the configured resolver:

```php
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;

$draft->withSubject(app(SubjectResolverInterface::class)->resolve($invoice));
```

### Scoped tags

```php
EagleEye::withTags(['tenant:42', 'import:2026-10'], function (): void {
    EagleEye::record('import.started');
    // model changes captured here are tagged too
});

eagle_eye_tag(['tenant:42'], fn () => $importer->run());
```

Tags are removed when the callback returns or throws.

### Silencing

```php
EagleEye::withoutLogging(fn () => $seeder->run());   // or eagle_eye_silent(...)

EagleEye::pause();
// ...
EagleEye::resume();
```

Silent blocks nest. Use them for imports, seeders, and bulk maintenance.

## Describing models

### Attributes

```php
use Dotswan\EagleEye\Attributes\ActivityField;
use Dotswan\EagleEye\Attributes\ActivityLabel;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Enums\RedactionMode;
use Illuminate\Database\Eloquent\Model;

#[ActivitySubject('invoice')]          // subject type (default: snake_case class basename)
#[ActivityLabel('number')]             // attribute used as display label
#[ActivityTags('billing', 'finance')]  // tags added to entries about this class
final class Invoice extends Model
{
}

// ActivityField targets declared properties, so use it on plain classes (DTOs, value objects):
final class PayoutData
{
    #[ActivityField(label: 'IBAN', redact: RedactionMode::Encrypt)]
    public string $iban;
}
```

`ActivityField::$label` feeds `FieldLabelResolverInterface`, and `ActivityField::$redact` sets the privacy mode of that payload field whenever the class is the entry's subject (`Subject::$className`). Eloquent attributes are not declared properties; for models, implement `DeclaresActivityPrivacy` instead (below).

### Computed labels and per-instance privacy

```php
use Dotswan\EagleEye\Contracts\DeclaresActivityPrivacy;
use Dotswan\EagleEye\Contracts\DescribesActivity;
use Dotswan\EagleEye\Enums\RedactionMode;

final class Customer extends Model implements DescribesActivity, DeclaresActivityPrivacy
{
    public function activityLogLabel(): string
    {
        return "Customer {$this->reference}";
    }

    /** @return array<string, RedactionMode> */
    public function activityPrivacy(): array
    {
        return [
            'email'      => RedactionMode::Partial,
            'tax_number' => RedactionMode::Encrypt,
            'notes'      => RedactionMode::Drop,
        ];
    }
}
```

`activityPrivacy()` must not run queries; use already-loaded attributes only. It is called at most once per model instance.

Label resolution order: `DescribesActivity`, `#[ActivityLabel]`, `subjects.per_class`, `subjects.label_attributes` (skipping attributes that are redacted), then `Class Name #<id>`.

### Excluding a class

```php
use Dotswan\EagleEye\Attributes\DoNotLog;

#[DoNotLog]
final class PersonalAccessToken extends Model
{
}
```

Any entry whose subject class carries `#[DoNotLog]` is dropped. For configuration-based exclusion use `filters.models.except` (see [Filtering](filtering.md)).

## Reading activity

### Eloquent trails

Every persisted model gains an `activityTrail()` method through a query builder macro:

```php
$history = $invoice->activityTrail()->limit(50)->get();          // entries about the invoice
$actions = $user->activityTrail('actor')->last('7 days')->get(); // entries performed by the user
```

The model must be persisted; the active driver must support queries.

### Query builder

```php
use DateTimeImmutable;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Facades\EagleEye;

$result = EagleEye::query()
    ->channels(ActivityChannel::Custom, ActivityChannel::Model)
    ->between(new DateTimeImmutable('2026-09-01T00:00:00Z'), new DateTimeImmutable('2026-10-01T00:00:00Z'))
    ->forSubject('invoice', 'INV-1042')
    ->withAnyTag(['billing'])
    ->whereStatus('>=', 400)
    ->search('declined')
    ->orderByTime(SortOrder::Descending)
    ->limit(100)
    ->get();

foreach ($result->entries as $entry) {
    echo $entry->loggedAt->format(DATE_ATOM).' '.$entry->title.PHP_EOL;
}
```

Trail methods: `channels()`, `between()`, `last('30 days')`, `forActor()`, `forSubject()`, `forSubjectClass()`, `forTrace()`, `withAnyTag()`, `whereStatus()`, `search()`, `orderByTime()`, `limit()` (1–500), `cursor()`, then `get()`, `count()`, or `aggregate()`. Every method returns a new trail; the original is unchanged.

For filters not exposed on the trail, build an `ActivityQuery` and pass it in:

```php
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivitySeverity;

$query = (new ActivityQuery())
    ->actions('invoice.approved', 'invoice.declined')        // exact action names
    ->severities(ActivitySeverity::Warning, ActivitySeverity::Error);

$result = EagleEye::query($query)->last('7 days')->get();
``` See [Queries](queries.md) for the full query API, pagination, aggregation, and driver-specific behaviour.

### Pagination

```php
use Dotswan\EagleEye\Data\Query\Cursor;

$page = EagleEye::query()->limit(100)->get();
$token = $page->nextCursor?->encode();                       // opaque string for clients

$next = EagleEye::query()->cursor(Cursor::decode($token))->limit(100)->get();
```

`QueryResult` exposes `entries`, `nextCursor`, `hasMore`, `count`, and `exactCount`.

### Reading an entry

`ActivityEntry` is a read-only value object. Key properties: `id`, `loggedAt`, `channel`, `action`, `severity`, `title`, `summary`, `actor`, `subject`, `trace` (`traceId`, `correlationId`, `causationId`, `parentSpanId`), `request` (method, URL, route, status code, duration, IP, user agent), `tags`, `payload`, `environment`, `hostname`. Encrypted values appear in the payload as `['__secret' => '<path>']`; decrypt them through `ActivityRepositoryInterface::reveal()`, which is authorised and audited (see [Privacy](privacy.md)).

## Testing your application

```php
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Facades\EagleEye;

it('records approval', function (): void {
    $fake = EagleEye::fake();

    $this->post('/invoices/42/approve')->assertOk();

    $fake->assertLogged(fn (ActivityEntry $entry): bool => $entry->action === 'invoice.approved'
        && $entry->payload['invoice_id'] === 42);
    $fake->assertNotLogged(fn (ActivityEntry $entry): bool => $entry->action === 'invoice.declined');
    $fake->assertTagged('billing');
});
```

`EagleEye::fake()` swaps only the storage for a bounded in-memory driver (1,000 entries by default, `fake(maxEntries: 5000)` up to 100,000). The real pipeline still runs, so filters, redaction, tags, and titles behave as in production. `$fake->recorded()` returns every entry and `$fake->assertCount($n)` checks the total.

In a test suite that does not care about activity, set `EAGLE_EYE_DRIVER=null` (or `EAGLE_EYE_ENABLED=false`) in `phpunit.xml`.
