# Contributing

Thank you for considering a contribution to Eagle Eye.

## Development setup

```bash
git clone https://github.com/dotswan/eagle-eye.git
cd eagle-eye
composer install
```

Requirements: PHP 8.4 or 8.5 with `ext-mbstring` and `ext-curl`, and Composer 2.

## Quality gate

Every pull request must pass the full gate, which CI runs on PHP 8.4/8.5 against Laravel 11 and 12:

```bash
composer check
```

`composer check` runs, in order:

| Script | What it checks |
|---|---|
| `composer dependency-audit` | Runtime `require` contains only PHP, `ext-mbstring`, and `illuminate/*` packages |
| `composer namespace-audit` | Package source never references host application namespaces |
| `composer deptrac` | Layer boundaries in `deptrac.yaml` (no uncovered classes) |
| `composer rector:dry` | Rector finds nothing to change |
| `composer lint` | Laravel Pint formatting (`vendor/bin/pint` fixes it) |
| `composer analyse` | PHPStan / Larastan level 8 over `src` and `tests` |
| `composer test` | The Pest suite |

Run a single file or test while iterating:

```bash
vendor/bin/pest tests/Feature/CaptureTest.php
vendor/bin/pest --filter="defers buffered delivery"
```

### Loki integration tests

Tests that need a real Loki are skipped unless `EAGLE_EYE_LOKI_TEST_URL` is set. To run them locally:

```bash
docker run --rm -d --name eagle-eye-loki -p 3100:3100 \
  -v "$PWD/tests/Support/loki-ci.yaml:/etc/loki/local-config.yaml:ro" \
  grafana/loki:3.4.2 -config.file=/etc/loki/local-config.yaml

EAGLE_EYE_LOKI_TEST_URL=http://127.0.0.1:3100 EAGLE_EYE_LOKI_MODE=metadata \
  vendor/bin/pest tests/Feature/LokiContractTest.php
```

`EAGLE_EYE_LOKI_MODE` accepts `metadata`, `json`, and `collector`.

### Database engines

The suite uses in-memory SQLite by default. Point the database driver tests at MySQL or PostgreSQL with the usual `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` variables.

## Guidelines

- Every behaviour change needs a test that fails without the change.
- Keep the runtime dependency surface to `illuminate/*`.
- Public contracts live in `src/Contracts` and the stored envelope in `src/Data`. Changing the envelope also requires updating the snapshot (`tests/Feature/Api/snapshots/envelope-v1.json`), the TypeScript contract (`resources/js/types/eagle-eye.d.ts`), and the docs.
- Update `CHANGELOG.md` under `## Unreleased` and the relevant page in `docs/`.
- Use clear commit messages; one logical change per pull request.

## Reporting security issues

See [SECURITY.md](SECURITY.md). Do not open public issues for vulnerabilities.
