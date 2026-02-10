**Complete Coding Guideline for the `awaisjameel/laravel-cpanel-hosting` Package**

This document serves as the single source of truth for the coding agent (or team) building the package. Read it entirely before starting any work. The goal is to build a production-grade, robust, secure, and extensible Laravel package that solves real cPanel/shared hosting pain points while following modern Laravel/PHP best practices (PSR-12, strict types, dependency injection, testing, etc.).

### 1. Package Vision & Enhancements (Beyond Original Specs)

**Core Purpose**  
Provide seamless cPanel hosting support for Laravel apps:

- Root-domain hosting (no `/public` in URLs).
- Secure, token-protected deployment/maintenance endpoints.
- Robust fallbacks for shared hosting limitations (symlinks, exec, file permissions).

**Key Enhancements for Robustness, Genericity & Future-Proofing**

- **Full deployment pipeline** in `/deploy` (sync-env → maintenance down → optimize:clear → migrate → cache → storage-link → maintenance up) with individual endpoints for granular control.
- **Configurable deploy steps** (array of Artisan commands or custom callables).
- **Webhook support** (GitHub, GitLab, Bitbucket) with signature verification as alternative to token.
- **IP whitelisting** (optional) + rate limiting (memory-based, safe even without cache table).
- **Deploy logging** (dedicated channel + file).
- **Maintenance mode integration** (down/up with secret).
- **Enhanced storage link**: `symlink` (preferred) → recursive mirror copy (with delete existing) → configurable public disk override.
- **Smart `.env` sync**: backup old `.env`, optional merge/diff, write permission checks, validation of required keys.
- **Health-check endpoint** (`/deploy/health`).
- **MySQL legacy compat** (191) + auto-detection + optional per-connection.
- **Comprehensive `.htaccess` stub**: security headers, deny sensitive paths, force HTTPS option, LiteSpeed/Apache compatibility.
- **Installer improvements**: interactive mode, backups of existing root files, `--force`, `--only-config`, `--only-root`, append to `.env.example`.
- **Events**: `DeployStarting`, `DeployStepCompleted`, `DeployCompleted` (so apps can hook in).
- **Testing**: 100%+ coverage for all public APIs, feature tests for routes, unit tests for middleware/storage logic.
- **Laravel support**: 10.x, 11.x, 12.x (use `laravel-package-tools` where helpful).
- **PHP**: 8.2+ with strict types, typed properties, enums where beneficial.
- **Security**: Constant-time token comparison, no session/CSRF on deploy routes, secure defaults.

The package remains focused on cPanel but is structured generically (e.g., `Hosting` namespace internally) for potential future expansion.

### 2. Setup from Spatie Skeleton (already done, but here’s the exact steps for clarity)

1. Go to https://github.com/spatie/package-skeleton-laravel → **Use this template** → create new repo named `laravel-cpanel-hosting` under your account (or directly under awaisjameel if you have access).
2. Clone it locally into `packages/awaisjameel/laravel-cpanel-hosting` (or wherever you develop packages).
3. Run `php ./configure.php` and fill:
    - Vendor slug: `awaisjameel`
    - Package slug: `laravel-cpanel-hosting`
    - Author name/email
    - Description: "Laravel package that makes cPanel/shared hosting painless – root domain support, secure deploy endpoints, storage link fallbacks, and more."
4. Update `composer.json`:
    ```json
    "name": "awaisjameel/laravel-cpanel-hosting",
    "description": "...",
    "license": "MIT",
    "authors": [...],
    "require": {
        "php": "^8.2",
        "illuminate/support": "^10.0|^11.0|^12.0"
    },
    "autoload": {
        "psr-4": {
            "AwaisJameel\\LaravelCpanelHosting\\": "src/"
        }
    },
    "autoload-dev": { ... },
    "extra": {
        "providers": [
            "Awaisjameel\\LaravelCpanelHosting\\LaravelCpanelHostingServiceProvider"
        ],
        "aliases": {
            "LaravelCpanelHosting": "Awaisjameel\\LaravelCpanelHosting\\Facades\\LaravelCpanelHosting"
        }
    }
    ```
5. Run `composer install`.
6. Delete unnecessary skeleton folders/files: `database/`, `resources/views/` (unless you add views later).
7. Rename `src/SkeletonServiceProvider.php` → `src/CpanelHostingServiceProvider.php`.
8. Update namespace everywhere to `AwaisJameel\LaravelCpanelHosting`.

**Final Folder Structure (Target)**

```
src/
  ├── CpanelHostingServiceProvider.php
  ├── LaravelCpanelHosting.php          // Optional facade/main class
  ├── Events/
  ├── Http/
  │   ├── Controllers/DeployController.php
  │   └── Middleware/EnsureDeployTokenIsValid.php
  ├── Console/InstallCpanelHostingCommand.php
  ├── Actions/                              // New: for reusable logic
  │   ├── SyncEnvAction.php
  │   ├── StorageLinkAction.php
  │   └── ...
  ├── Support/                              // Helpers, DTOs
  ├── ...
config/
  cpanel-hosting.php
routes/
  deploy.php
stubs/
  root-index.php.stub
  root-htaccess.stub
tests/
  Feature/
  Unit/
.gitignore, phpunit.xml.dist, phpstan.neon, etc. (keep from skeleton)
```

### 3. Config (`config/cpanel-hosting.php`)

Expand the original:

```php
<?php

return [
    'enabled' => env('CPANEL_DEPLOY_ENABLED', false),
    'token' => env('CPANEL_DEPLOY_TOKEN'),                    // plain long random string
    'webhook_secret' => env('CPANEL_DEPLOY_WEBHOOK_SECRET'), // for GitHub etc.
    'route_prefix' => env('CPANEL_DEPLOY_PREFIX', 'deploy'),
    'allowed_ips' => env('CPANEL_DEPLOY_ALLOWED_IPS'),        // comma-separated or array
    'sync_env' => [
        'source' => env('CPANEL_SYNC_ENV_SOURCE', '.env.server'),
        'target' => env('CPANEL_SYNC_ENV_TARGET', '.env'),
        'backup' => true,
    ],
    'storage_link' => [
        'prefer_symlink' => env('CPANEL_STORAGE_LINK_PREFER_SYMLINK', true),
        'fallback_copy' => true,
    ],
    'mysql_legacy_compat' => [
        'enabled' => env('CPANEL_MYSQL_LEGACY_COMPAT', true),
        'length' => 191,
    ],
    'pipeline' => [                                           // New
        'default_steps' => ['sync-env', 'maintenance-down', 'optimize-clear', 'migrate', 'cache', 'storage-link', 'maintenance-up'],
    ],
    'maintenance' => [
        'secret' => env('APP_MAINTENANCE_SECRET'),
    ],
];
```

Publish tag: `cpanel-hosting-config`.

### 4. Service Provider (`CpanelHostingServiceProvider.php`)

Use `spatie/laravel-package-tools` (already in skeleton) for clean registration.

Responsibilities (in order):

- `mergeConfigFrom`
- `register` → bind any singletons, actions, etc.
- `boot`:
    - If enabled and not in console, load routes (stateless group).
    - Apply MySQL legacy compat (only for mysql/mariadb, respect default connection or all mysql connections).
    - Register console command.
    - Publish config + stubs (tags: `cpanel-hosting-config`, `cpanel-hosting-stubs`).
    - Register events.

**Important**: Routes loaded only if `config('cpanel-hosting.enabled')`.

### 5. Routes (`routes/deploy.php`)

```php
<?php

use AwaisJameel\LaravelCpanelHosting\Http\Controllers\DeployController;
use AwaisJameel\LaravelCpanelHosting\Http\Middleware\EnsureDeployTokenIsValid;

Route::prefix(config('cpanel-hosting.route_prefix'))
    ->middleware([
        EnsureDeployTokenIsValid::class,
        // Explicitly exclude session, CSRF, throttle
    ])
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        // any throttle if present
    ])
    ->group(function () {
        Route::get('/', [DeployController::class, 'deploy']);           // full pipeline
        Route::get('sync-env', [DeployController::class, 'syncEnv']);
        Route::get('clear', [DeployController::class, 'clear']);
        Route::get('migrate', [DeployController::class, 'migrate']);
        Route::get('migrate-fresh', [DeployController::class, 'migrateFresh']); // warn in docs
        Route::get('cache', [DeployController::class, 'cache']);
        Route::get('queue-restart', [DeployController::class, 'queueRestart']);
        Route::get('storage-link', [DeployController::class, 'storageLink']);
        Route::get('health', [DeployController::class, 'health']);
        // Add maintenance-down/up, optimize, etc.
    });
```

Support `?token=...` **and** header `X-Deploy-Token`.

### 6. Middleware (`EnsureDeployTokenIsValid.php`)

- If `!config('cpanel-hosting.enabled')` → `abort(404)` (or 503 in production? configurable).
- Check IP whitelist if configured (`hash_equals` or `in_array` with `Request::ip()`).
- Token from header (preferred) or query string.
- `hash_equals(config('cpanel-hosting.token'), $provided)`.
- Also support webhook signature verification if `webhook_secret` present and `X-Hub-Signature-256` header (GitHub style).
- 403 on failure, with generic message (no info leak).

### 7. DeployController

- Use actions (e.g., `SyncEnvAction`, `StorageLinkAction`) for logic.
- Every method:
    - Try/catch → standardized JSON: `['success' => bool, 'message' => string, 'data' => [], 'errors' => []]`
    - Dispatch events.
    - Log to `deploy` channel.
- `deploy()`: run configurable pipeline (use `Pipeline` facade or custom loop).
- `storageLink()`:
    ```php
    $action = app(StorageLinkAction::class);
    $result = $action->execute();
    ```
- Wrap `Artisan::call()` with output capture.

**Best Practice**: Keep controller thin. All heavy logic in dedicated Action classes (single responsibility).

### 8. StorageLinkAction (New, Critical for Robustness)

- If `prefer_symlink` && `function_exists('symlink')` && symlink succeeds → done.
- Else if `fallback_copy`:
    - Delete `public/storage` if exists (and is directory, not symlink).
    - `File::copyDirectory(storage_path('app/public'), public_path('storage'))`.
    - Set correct permissions (0755/0644) where possible.
- Return detailed result for logging/JSON.
- Handle edge cases: permission denied, disk full, existing broken symlink, very large public files (log warning).

### 9. Installer Command (`InstallCpanelHostingCommand.php`)

Signature: `cpanel-hosting:install {--force} {--only-config} {--only-root}`

Steps:

1. Publish config (with force).
2. Copy stubs to root (`base_path('index.php')`, `base_path('.htaccess')`) if missing or `--force`. **Backup existing** with timestamp.
3. Interactive questions (if not --no-interaction): confirm token generation, enable deploy, etc.
4. Append missing env keys to `.env.example` (use `file_get_contents` + smart append).
5. Success message with next steps (env vars, upload, etc.).

### 10. Root Stubs

**`root-index.php.stub`** (keep simple but strict_types):

```php
<?php

declare(strict_types=1);

require __DIR__.'/public/index.php';
```

**`root-htaccess.stub`** (enhanced, production-ready):

- `RewriteEngine On`
- Redirect `/public/*` → clean URLs.
- Deny access to: `.env`, `.git`, `app/`, `bootstrap/`, `config/`, `database/`, `storage/` (except public), `vendor/`, `tests/`, etc.
- Serve static files from `/public`.
- Route everything else to `public/index.php`.
- Optional: Security headers (`X-Frame-Options`, `Content-Security-Policy` skeleton, HSTS).
- Optional: Force HTTPS.
- LiteSpeed + Apache compatible comments.
- Comprehensive comments explaining each section.

### 11. Best Practices the Coding Agent Must Follow

- **Code Style**: PSR-12, `declare(strict_types=1);`, typed everything possible, final classes where appropriate.
- **Structure**: Actions for business logic, DTOs/Requests for input, Events for extensibility.
- **Error Handling**: Never expose sensitive info. Use `report()` for exceptions.
- **Testing**:
    - Feature tests for all routes (mock Artisan, filesystem).
    - Unit tests for actions and middleware.
    - Use `Orchestra\Testbench`.
    - Test edge cases: disabled config, invalid token, no symlink, .env not writable, etc.
- **Dependencies**: Minimal. Only Laravel core + `spatie/laravel-package-tools` if helpful.
- **Documentation**: Keep `README.md` excellent (installation, usage, security notes, troubleshooting, cPanel-specific tips).
- **CI**: Keep GitHub Actions from skeleton (tests, PHPStan, Pint).
- **Versioning**: Semantic versioning. Start at 1.0.0.

### 12. Security & Production Notes (Include in README)

- Treat `CPANEL_DEPLOY_TOKEN` as secret (rotate on exposure).
- Prefer header over query string.
- Always `APP_DEBUG=false`.
- `migrate-fresh` is destructive — document heavily.
- Use webhook signatures when possible (better for CI/CD).
- Monitor deploy logs.

### 13. Development Workflow for Agent

1. Set up skeleton → configure.php.
2. Update composer.json, namespace, provider.
3. Implement config + service provider.
4. Implement middleware.
5. Implement actions (storage link first — most critical).
6. Implement controller + routes.
7. Implement installer command + stubs.
8. Add events + logging.
9. Write tests (aim for high coverage).
10. Update README.md (full guide, examples, security).
11. Run `composer test`, PHPStan, Pint.
12. Test manually in a fresh Laravel app on local + simulated cPanel environment (or real shared hosting if possible).

**Before committing any change**: Re-read relevant sections of this guideline and verify the change aligns with robustness, edge-case coverage, and best practices.

Once complete, the package should feel like a professional Spatie-level tool — reliable even on the most restrictive shared hosts.

Start building. If anything is unclear, ask for clarification before proceeding. Good luck — this will be very useful to the Laravel community!
