# Eagle Eye

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dotswan/eagle-eye.svg?style=flat-square)](https://packagist.org/packages/dotswan/eagle-eye)
[![CI](https://img.shields.io/github/actions/workflow/status/dotswan/eagle-eye/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/dotswan/eagle-eye/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/dotswan/eagle-eye.svg?style=flat-square)](LICENSE.md)

Driver-agnostic activity and audit logging for Laravel.

Eagle Eye records what happens in your application (HTTP requests, model changes, logins, jobs, commands, mail, notifications, outbound API calls, exceptions) without any application code, links everything that belongs to one request into a trace, redacts personal data before it is stored, and writes it to the database or Grafana Loki through one stable entry format.

## Why Eagle Eye

- **Zero-code capture.** Install, migrate, and every request, model change, and login is in the log. Add your own domain events with one line.
- **One envelope, any storage.** The same `ActivityEntry` is stored in SQL, Loki, or your own driver, and read through the same query API.
- **Privacy by default.** Passwords, tokens, and card data are masked; emails, phone numbers, and names are partially masked; secrets can be encrypted and revealed only through an authorised, audited call. Subject erasure and retention are built in.
- **Traces, not just rows.** Every entry of a request, the jobs it dispatched, and the outbound calls it made share a trace id and a causal chain.
- **Off the hot path.** Entries are buffered and written after the response is sent, with bounded memory and a disk spool when storage is down.

## Features

- Automatic capture of HTTP, Eloquent, auth, queue, console and scheduler, mail, notifications, outbound HTTP (Laravel `Http` and Guzzle), exceptions, and slow queries, plus a per-request SQL summary
- Manual recording through a facade, helper, or fluent `ActivityDraft`
- Drivers: `database`, `loki`, `multi` (Loki + SQL index), `index`, `memory`, `null`, and custom drivers with a conformance test suite
- Redaction modes `mask`, `partial`, `hash`, `encrypt`, `drop`, `keep`, path and key rules, per-model privacy declarations, IP truncation
- Audited secret reveal, subject erasure with replay barriers, retention by channel/severity/tag, optional HMAC integrity ledger
- W3C `traceparent` support, queue trace propagation, causal trace trees
- Deterministic per-trace sampling and deny-first filtering with per-route overrides
- Fluent queries, cursor pagination, aggregation and time series, `activityTrail()` on every model
- Optional JSON API with a TypeScript contract, localised human-readable titles
- `EagleEye::fake()` for tests, and 14 `activity-log:*` Artisan commands

## Requirements

- PHP 8.4 or 8.5 with `ext-mbstring`
- Laravel 12 or 13
- `ext-curl` for the Loki driver's HTTP push and query API

## Installation

```bash
composer require dotswan/eagle-eye
php artisan vendor:publish --tag=eagle-eye-migrations
php artisan migrate
```

Optionally publish the configuration and title translations:

```bash
php artisan vendor:publish --tag=eagle-eye-config
php artisan vendor:publish --tag=eagle-eye-lang
```

See [Installation](docs/installation.md) for the full guide, including scheduling and verification.

## Quick start

### Database driver (default)

```dotenv
EAGLE_EYE_DRIVER=database
# Required outside local/testing: 32+ random bytes, never rotated with APP_KEY
EAGLE_EYE_SECURITY_KEY=   # php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

That is all. Open a page, edit a model, log in, then:

```bash
php artisan activity-log:tail
```

### Grafana Loki

Write one JSON line per entry to stdout for your log collector, and read back through the Loki API:

```dotenv
EAGLE_EYE_DRIVER=loki
EAGLE_EYE_LOKI_WRITE=stdout
EAGLE_EYE_LOKI_READ_SELECTOR={namespace="my-app-prod",container="app"}
LOKI_QUERY_URL=http://loki:3100
EAGLE_EYE_SECURITY_KEY=...
```

Or push directly to Loki:

```dotenv
EAGLE_EYE_DRIVER=loki
EAGLE_EYE_LOKI_WRITE=push
LOKI_PUSH_URL=http://loki:3100
LOKI_TENANT_ID=my-app
EAGLE_EYE_SECURITY_KEY=...
```

Run the migration with Loki too: privacy and integrity state is kept in SQL. See [Loki](docs/loki.md).

## Usage

```php
use Dotswan\EagleEye\Facades\EagleEye;

// Record a domain event
EagleEye::record('invoice.approved', ['invoice_id' => 42]);

// Same, through the helper
eagleEye()->record('invoice.approved', ['invoice_id' => 42]);

// Tag everything recorded inside the callback (including captured model changes)
EagleEye::withTags(['tenant:42'], fn () => $importer->run());

// Record nothing inside the callback
EagleEye::withoutLogging(fn () => $seeder->run());

// Read activity
$history = $invoice->activityTrail()->limit(50)->get();
$recent  = EagleEye::query()->forActor('user', 8821)->last('7 days')->get();
```

Describe your models with attributes and contracts:

```php
use Dotswan\EagleEye\Attributes\ActivityLabel;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Contracts\DeclaresActivityPrivacy;
use Dotswan\EagleEye\Enums\RedactionMode;

#[ActivityLabel('number')]
#[ActivityTags('billing')]
final class Invoice extends Model implements DeclaresActivityPrivacy
{
    public function activityPrivacy(): array
    {
        return ['iban' => RedactionMode::Encrypt, 'customer_email' => RedactionMode::Partial];
    }
}
```

Test it:

```php
$fake = EagleEye::fake();

$this->post('/invoices/42/approve');

$fake->assertLogged(fn ($entry) => $entry->action === 'invoice.approved');
```

See [Usage](docs/usage.md) for drafts, subjects, queries, pagination, and testing.

## Documentation

| Page | Contents |
|---|---|
| [Installation](docs/installation.md) | Requirements, setup, security key, scheduling, verification |
| [Configuration](docs/configuration.md) | Every configuration key and environment variable |
| [Usage](docs/usage.md) | Recording, model attributes, trails, queries, testing |
| [Integration](docs/integration.md) | Everything the package registers (routes, tables, commands, helpers, bindings) and coexistence with other packages |
| [Performance](docs/performance.md) | Per-request cost, buffering, transports, memory bounds, production tuning, Octane |
| [Drivers](docs/drivers.md) | Shipped drivers, capabilities, custom drivers |
| [Loki](docs/loki.md) | Loki write modes, collector setup, LogQL translation, retention |
| [Filtering](docs/filtering.md) | Filter rules, overrides, request parts, custom rules |
| [Privacy](docs/privacy.md) | Redaction, encryption and reveal, erasure, retention, integrity |
| [Correlation](docs/correlation.md) | Trace ids, causal chains, W3C propagation, queue propagation |
| [Queries](docs/queries.md) | Query API, pagination, aggregation, time series |
| [API](docs/api.md) | Optional JSON API and TypeScript contract |
| [Extending](docs/extending.md) | Pipes, enrichers, title resolvers, policies, resolvers |
| [Architecture](docs/architecture.md) | How the pieces fit together, ADRs |
| [Upgrading](docs/upgrade.md) | Versioning policy and upgrade checklist |

## Artisan commands

`activity-log:doctor`, `stats`, `tail`, `trail`, `trace`, `export`, `prune`, `forget`, `verify`, `ship`, `loki-ship`, `loki-config`, `cache`, `make-driver`. See [Integration](docs/integration.md#console-commands).

## Testing

```bash
composer install
composer check   # audits, Deptrac, Rector, Pint, PHPStan, Pest
composer test    # Pest only
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for running the Loki and MySQL integration suites.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please report vulnerabilities by email to tech@dotswan.com instead of opening an issue. See [SECURITY.md](SECURITY.md).

## License

Eagle Eye is open-source software licensed under the [MIT license](LICENSE.md).
