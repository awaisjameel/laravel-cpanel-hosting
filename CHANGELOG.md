# Changelog

All notable changes to `laravel-cpanel-hosting` will be documented in this file.

## v2.0.0 - 2026-10-26

- Enforce Pint, PHPStan, strict manifest validation and dependency audits on pull requests and pushes; consolidate duplicate/automatic-formatting workflows.

- Require POST for every mutating action and header/webhook authentication; remove mutating GET routes and query-string token authentication. Health remains GET.

- Support maintained PHP 8.2–8.5 and Laravel 12–13; refresh runtime and development dependencies and CI.
- Validate environment syntax and required keys before atomic replacement; use private, unique backups and preserve the active file on validation failure.
- Stage storage links/copies before replacement; reject overlapping paths and protected roots, preserve sources and restore the previous target if promotion fails.
- Use persistent Laravel cache-backed rate limiting with a file store by default and return `Retry-After` when throttled.
- Register deploy routes during production console boot for route caching and keep authenticated deployment endpoints reachable through normal maintenance middleware.
- Unify pipeline and endpoint execution, fix the `cache` helper/name collision, validate callback results, handle callback failures and redact sensitive Artisan parameters/output.
- Add container-resolved deployment step classes for cacheable configuration; empty pipelines fail without implicit migrations.
- Make installer failures return nonzero exit codes, validate option combinations, atomically write files and safely quote literal environment values.
- Fix Apache public redirects, subdirectory hosting, storage assets, environment/backup protection and AutoSSL challenges; add actual Apache HTTP regression tests.

## v1.1.0 - Laravel 13 & PHP 8.5 support - 2026-07-20

- Added support for Laravel 13 (`illuminate/contracts`/`illuminate/support` `^13.0`) and `orchestra/testbench` `^11.0`.
- Dropped support for Laravel 10, which reached end of security support in February 2025 and can no longer be tested against the current Pest/Testbench toolchain.
- Fixed `StorageLinkAction`'s copy-fallback permission step, which called `Illuminate\Filesystem\Filesystem::allDirectories()` — a method that only exists on Laravel 12+. On Laravel 11 this threw a `BadMethodCallException` after every successful directory copy, causing the storage-link copy fallback (the primary path on shared hosts without `symlink()`) to always report failure. Replaced with a version-independent `RecursiveDirectoryIterator` walk.
- Removed unused Spatie skeleton leftovers (`database/migrations`, `database/factories`, `resources/views`) that were never wired into the service provider.
- Expanded CI matrix to PHP 8.3–8.5 and Laravel 11–13.

## v1.0.0 - Initial Stable Release - 2026-02-10

`laravel-cpanel-hosting` `v1.0.0` is the first stable release.

- Added secure deploy endpoints with token auth (`X-Deploy-Token` or `?token=`), optional IP allowlist, webhook secret validation, and optional in-memory rate limiting.
- Added full deploy pipeline support with configurable steps and granular endpoints (`deploy`, `sync-env`, `clear`, `migrate`, `cache`, `queue-restart`, `storage-link`, `maintenance-down`, `maintenance-up`, `optimize`, `health`).
- Added robust actions for `.env` sync (backup + required key checks) and storage linking (symlink with copy fallback for restricted shared hosting).
- Added installer command: `php artisan cpanel-hosting:install` with `--force`, `--only-config`, and `--only-root`.
- Added root stubs for cPanel hosting: `index.php` passthrough and hardened `.htaccess`.
- Added deploy lifecycle events: `DeployStarting`, `DeployStepCompleted`, `DeployCompleted`.
- Added deploy logging channel support and MySQL legacy compatibility handling.
- Updated package docs/config for production usage.
- Added feature/unit tests for deploy routes, middleware behavior, and critical actions.
- Verified quality with passing test suite and static analysis.
