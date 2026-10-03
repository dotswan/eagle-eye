# Upgrading and versioning

Eagle Eye follows [Semantic Versioning](https://semver.org/). Changes are listed in [CHANGELOG.md](../CHANGELOG.md).

## Public contract

The following are covered by SemVer; a breaking change to any of them requires a new major version:

- interfaces in `src/Contracts`, the value objects in `src/Data`, the enums in `src/Enums`, and the attributes in `src/Attributes`;
- the `EagleEye` facade methods, the helper functions, and the `activityTrail()` macro;
- configuration keys and environment variables in `config/eagle-eye.php`;
- console command names, arguments, and options;
- API route names, URIs, parameters, and the JSON envelope (`schema_version`), together with `resources/js/types/eagle-eye.d.ts`;
- the stored entry format (`ActivityEntry::toStorage()`) and the database schema created by the package migrations;
- container tags (`eagle-eye.title_resolvers`, `eagle-eye.enrichers`, `eagle-eye.guzzle_middleware`) and the translation keys in `lang/en/titles.php`.

Classes marked `@internal`, the concrete pipes, capture adapters, and drivers' private SQL/LogQL may change in minor releases. Depend on contracts rather than concrete classes.

## Minor and patch releases

- New configuration keys always have defaults. Compare your published `config/eagle-eye.php` with the package version after upgrading and copy new keys you want to change.
- If you published the `pipeline` list, check the changelog for new pipes; `activity-log:doctor` warns about missing required pipes such as `RunEnrichers`.
- New database columns or tables ship as new migrations. Publish them again with `php artisan vendor:publish --tag=eagle-eye-migrations` (existing files are not overwritten) and run `php artisan migrate`.
- If you published translations (`eagle-eye-lang`), new title keys fall back to the package's English text until you add them.

## Major releases

Each major release has a section here with the required steps. Stored entries are versioned through `schema_version`, so drivers can read entries written by earlier versions of the same major line.

## Upgrade checklist

1. Read the changelog entries between your version and the target.
2. `composer update dotswan/eagle-eye`.
3. Publish and run new migrations.
4. Merge new configuration keys if you publish the config file.
5. `php artisan activity-log:doctor`.
6. Run your test suite (`EagleEye::fake()` assertions exercise the real pipeline).
