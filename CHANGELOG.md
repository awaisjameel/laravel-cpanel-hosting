# Changelog

All notable changes to `laravel-cpanel-hosting` will be documented in this file.

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

If you want, I can also generate a shorter “GitHub Releases style” version and a `CHANGELOG.md` entry block.
