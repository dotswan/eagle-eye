# Installation

## Requirements

- PHP 8.4 or 8.5 with `ext-mbstring`
- Laravel 12 or 13
- `ext-curl` when you use the Loki driver's HTTP push or query API (Loki calls require cURL to enforce whole-operation deadlines)
- A database supported by Laravel (SQLite, MySQL, PostgreSQL) for the `database` driver and for the privacy and integrity state used by every driver

## 1. Install the package

```bash
composer require dotswan/eagle-eye
```

The service provider is auto-discovered.

## 2. Set the security key

Eagle Eye needs a dedicated secret of at least 32 bytes. It authenticates erasure barriers, erasure receipts, delivery attempts, and the integrity ledger, and must stay stable when `APP_KEY` is rotated.

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

```dotenv
EAGLE_EYE_SECURITY_KEY=<generated value>
```

- Use the same value on every web, queue, and scheduler process.
- Store it in your secret manager and back it up with the database. Eagle Eye stores its fingerprint and refuses to run with a different key.
- In `local` and `testing` the application key is used when the variable is missing. In every other environment a missing or short key is a configuration error and Eagle Eye fails to resolve.

## 3. Publish and run the migration

```bash
php artisan vendor:publish --tag=eagle-eye-migrations
php artisan migrate
```

The single migration creates the `activity_logs` table for the `database` driver, the `activity_log_index` table for the `multi` driver, and the tables for security-key state, delivery attempts, erasure barriers and receipts, and the integrity ledger. Run it even if you store entries in Loki. See [Integration](integration.md#database-tables) for the full list.

## 4. Publish the configuration (optional)

```bash
php artisan vendor:publish --tag=eagle-eye-config
```

The defaults work without publishing. Publish when you want to change filters, privacy rules, retention, or the pipeline. Translations for entry titles can be published with `--tag=eagle-eye-lang`.

## 5. Verify

```bash
php artisan activity-log:doctor
```

`doctor` checks the driver, capabilities, retention, spool, pipeline, and Loki label configuration and fails on an invalid setup. Then exercise the application (open a page, change a model, log in) and look at the result:

```bash
php artisan activity-log:tail --limit=10
php artisan activity-log:stats
php artisan activity-log:trail --user=user:1
```

Or record a smoke entry from Tinker:

```php
eagleEye()->record('installation.verified');
```

## 6. Schedule maintenance

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('activity-log:prune')->daily();
Schedule::command('activity-log:ship')->everyFiveMinutes()->withoutOverlapping();
```

`prune` applies the retention rules (30 days by default, 365 days for `auth`). `ship` replays entries that were spooled to disk because the driver was unavailable. With the Loki push writer, also schedule `activity-log:loki-ship`.

## Choosing a driver

| Scenario | Setting |
|---|---|
| Single application, no extra infrastructure | `EAGLE_EYE_DRIVER=database` (default) |
| Grafana Loki with a log collector | `EAGLE_EYE_DRIVER=loki`, `EAGLE_EYE_LOKI_WRITE=stdout`, `EAGLE_EYE_LOKI_READ_SELECTOR=...` |
| Grafana Loki via push API | `EAGLE_EYE_DRIVER=loki`, `EAGLE_EYE_LOKI_WRITE=push`, `LOKI_PUSH_URL=...` |
| Loki plus fast SQL listings and exact counts | `EAGLE_EYE_DRIVER=multi` (primary `loki`, accelerator `index`) |
| Discard everything (for example in a test suite) | `EAGLE_EYE_DRIVER=null` |

See [Drivers](drivers.md) and [Loki](loki.md).

## Exposing activity to users

To read activity from a front end, enable the JSON API (`EAGLE_EYE_API_ENABLED=true`), add authentication middleware to `api.middleware`, and define the `viewActivityLog` and `revealActivityLog` gates (or bind your own `LogAccessPolicyInterface`). See [API](api.md).

## Uninstalling

1. Remove scheduled `activity-log:*` commands.
2. Roll back or drop the package tables (see [Integration](integration.md#database-tables)) after exporting what you must keep (`php artisan activity-log:export --output=activity.ndjson`).
3. Delete `config/eagle-eye.php`, `lang/vendor/eagle-eye`, and the spool files under `storage/`.
4. `composer remove dotswan/eagle-eye`.
