# Laravel cPanel Hosting

A robust, secure, and production-ready Laravel package that makes deploying Laravel applications on **cPanel / shared hosting** painless and professional.

Shared hosting doesn't give you SSH-driven CI/CD, so this package exposes a small set of authenticated HTTP endpoints that let a webhook (GitHub, GitLab, or your own script) drive a deployment: pull the latest code (outside the scope of this package), then hit `/deploy` to sync the environment, run migrations, rebuild caches, relink storage, and flip maintenance mode — all guarded by a token, an IP allowlist, and optional rate limiting.

## Features

- **Secure deploy endpoints** — token (`X-Deploy-Token` header) or webhook signature (`X-Hub-Signature-256` / `X-Gitlab-Token`) authentication, optional IP allowlist, optional persistent cache-backed rate limiting.
- **Configurable deploy pipeline** — a single `POST /deploy` runs an ordered list of steps (strings, artisan command arrays, or closures) and stops or continues on failure per your config.
- **Granular endpoints** — every pipeline step is also its own route, so you can call `storage-link` or `migrate` on their own.
- **`.env` sync** — copies a server-side env file (e.g. `.env.server`) over `.env`, with automatic timestamped backups and required-key validation (`APP_KEY` by default).
- **Storage link fallback** — tries `symlink()` first, and transparently falls back to a recursive directory copy (with correct file/directory permissions) on hosts where `symlink()` is disabled.
- **Installer command** — publishes config, root `index.php` passthrough and hardened `.htaccess` stubs for cPanel's `public_html` layout, and (interactively) writes your deploy token/prefix straight into `.env`.
- **Dedicated deploy log channel** — auto-registered if you haven't already defined one, so deploy activity doesn't get lost in `laravel.log`.
- **Deploy lifecycle events** — `DeployStarting`, `DeployStepCompleted`, `DeployCompleted` for hooking in notifications (Slack, email, etc.).
- **MySQL legacy compatibility** — automatically applies `Schema::defaultStringLength()` for older MySQL/MariaDB versions still common on shared hosting (`utf8mb4` + short index key limits).

## Requirements

- PHP 8.2–8.5
- Laravel 12 or 13 (Laravel 13 requires PHP 8.3+)
- Apache 2.4 with `mod_rewrite` and `AllowOverride All` for the root hosting stub.

## Installation

```bash
composer require awaisjameel/laravel-cpanel-hosting
```

Run the installer:

```bash
php artisan cpanel-hosting:install
```

This will:

1. Publish `config/cpanel-hosting.php`.
2. Install `index.php` and `.htaccess` at your project root (skip existing files unless `--force`; create unique backups before replacement), so the app root can be pointed at your Laravel project directory directly instead of `public/` on cPanel.
3. Append the package's env keys to `.env.example` if they're missing.
4. When run interactively, prompt you to generate/set a deploy token, choose a route prefix, and optionally enable deploy routes immediately — writing the answers straight into `.env`.

Installer options:

| Option          | Effect                                                  |
|-----------------|----------------------------------------------------------|
| `--force`       | Overwrite existing root `index.php` / `.htaccess` / config instead of skipping them. |
| `--only-config` | Publish only the config file, skip the root stubs.       |
| `--only-root`   | Install only the root stubs, skip publishing config.      |

Non-interactively (e.g. in CI or a deploy script), the command skips the `.env` prompts and just publishes files:

```bash
php artisan cpanel-hosting:install --no-interaction
```

## Configuration

Publish the config manually if you skipped it during install:

```bash
php artisan vendor:publish --tag="cpanel-hosting-config"
```

Every option reads from an environment variable so `config/cpanel-hosting.php` rarely needs to be touched directly:

```dotenv
# Core
CPANEL_DEPLOY_ENABLED=false
CPANEL_DEPLOY_TOKEN=
CPANEL_DEPLOY_WEBHOOK_SECRET=
CPANEL_DEPLOY_PREFIX=deploy
CPANEL_DEPLOY_ALLOWED_IPS=
CPANEL_DEPLOY_STOP_ON_FAILURE=true
CPANEL_DEPLOY_LOG_CHANNEL=deploy

# Rate limiting (applied per client IP)
CPANEL_DEPLOY_RATE_LIMIT_ENABLED=false
CPANEL_DEPLOY_RATE_LIMIT_STORE=file
CPANEL_DEPLOY_RATE_LIMIT_MAX_ATTEMPTS=30
CPANEL_DEPLOY_RATE_LIMIT_DECAY_SECONDS=60

# Env sync
CPANEL_SYNC_ENV_SOURCE=.env.server
CPANEL_SYNC_ENV_TARGET=.env
CPANEL_SYNC_ENV_BACKUP=true

# Storage link
CPANEL_STORAGE_LINK_PREFER_SYMLINK=true
CPANEL_STORAGE_LINK_FALLBACK_COPY=true
CPANEL_STORAGE_LINK_SOURCE=app/public
CPANEL_STORAGE_LINK_PUBLIC_PATH=storage

# MySQL legacy compatibility (utf8mb4 index-length limit)
CPANEL_MYSQL_LEGACY_COMPAT=true
CPANEL_MYSQL_LEGACY_LENGTH=191
CPANEL_MYSQL_LEGACY_ALL_CONNECTIONS=false

# Maintenance mode secret bypass (Laravel's php artisan down --secret=)
APP_MAINTENANCE_SECRET=
```

| Key | Default | Notes |
|---|---|---|
| `enabled` | `false` | Deploy routes only register when this is `true`. Keep it `false` until you're ready. |
| `token` | `null` | Shared secret compared with `hash_equals()`. Required unless you're using webhook signatures instead. |
| `webhook_secret` | `null` | Enables `X-Hub-Signature-256` (GitHub, HMAC-SHA256 over the raw body) and `X-Gitlab-Token` (direct compare) auth. |
| `route_prefix` | `deploy` | Prefix all deploy routes live under. |
| `allowed_ips` | `null` | Comma-separated string or array of IPs; when set, only listed IPs may reach deploy routes (checked before auth). |
| `rate_limit.*` | disabled | Persistent per-IP Laravel limiter. The default `file` cache store requires no database table; select a shared store for multiple application servers. A 429 response includes `Retry-After`. |
| `sync_env.source` / `target` | `.env.server` / `.env` | Paths are resolved relative to the app base path unless absolute. |
| `sync_env.backup` | `true` | Validates syntax and required keys before replacement. Writes a private, unique `{target}.backup.{YmdHis}.{random}` backup and atomically replaces the active file. |
| `sync_env.required_keys` | `['APP_KEY']` | Sync fails if any of these keys are absent from the synced file. Edit the published config to add more (e.g. `DB_PASSWORD`). |
| `storage_link.prefer_symlink` | `true` | Try `symlink()` first. |
| `storage_link.fallback_copy` | `true` | If `symlink()` is unavailable or fails, recursively copy instead (with `0755`/`0644` permissions applied). |
| `storage_link.source` / `public_path` | `app/public` / `storage` | Resolved via `storage_path()` / `public_path()` unless absolute. |
| `mysql_legacy_compat.enabled` | `true` | Calls `Schema::defaultStringLength()` on boot when the active connection driver is `mysql`/`mariadb`. |
| `mysql_legacy_compat.all_connections` | `false` | When `true`, checks all configured connections instead of just `database.default`. |
| `pipeline.default_steps` | see below | The ordered list of steps `POST /deploy` runs. |
| `pipeline.stop_on_failure` | `true` | Stop the pipeline at the first failed step, or run all steps and report an overall failure. |
| `maintenance.secret` | `null` | Passed as `--secret` to `php artisan down`, letting you obtain Laravel’s bypass cookie by visiting `/{secret}`. |
| `logging.channel` | `deploy` | Auto-registered as a `single` driver writing to `storage/logs/cpanel-deploy.log` if you haven't defined this channel yourself. |

## Endpoints

Once `CPANEL_DEPLOY_ENABLED=true`, routes are registered under `CPANEL_DEPLOY_PREFIX` (default `deploy`):

| Method & Path | Purpose |
|---|---|
| `POST /deploy` | Runs the full pipeline (`pipeline.default_steps`). |
| `POST /deploy/sync-env` | Copies `sync_env.source` over `sync_env.target`. |
| `POST /deploy/clear` | `optimize:clear`. |
| `POST /deploy/migrate` | `migrate --force`. |
| `POST /deploy/migrate-fresh` | `migrate:fresh --force` — **destructive**, drops all tables. |
| `POST /deploy/cache` | `config:cache`, `route:cache`, `view:cache`, `event:cache`. |
| `POST /deploy/queue-restart` | `queue:restart`. |
| `POST /deploy/storage-link` | Symlink (or copy-fallback) `storage/app/public` into `public/storage`. |
| `POST /deploy/maintenance-down` | `down --retry=60`, plus `--secret` if `maintenance.secret` is set. |
| `POST /deploy/maintenance-up` | `up`. |
| `POST /deploy/optimize` | `optimize`. |
| `GET /deploy/health` | Unauthenticated-payload health check (still requires deploy auth) — returns `app_env`, timestamp, and route prefix. |

Every endpoint returns a consistent JSON shape:

```json
{
    "success": true,
    "message": "Deployment pipeline completed.",
    "data": { "steps": [ { "step": "sync-env", "result": { "...": "..." } } ] },
    "errors": []
}
```

Deploy routes deliberately bypass the session, CSRF, and default `throttle` middleware (see `routes/deploy.php`) since requests come from webhooks/CLI, not a browser session — auth is entirely handled by [`EnsureDeployTokenIsValid`](src/Http/Middleware/EnsureDeployTokenIsValid.php).

### Authentication

Mutating actions accept POST only; GET returns 405. Health remains GET. For example:

```bash
curl --fail-with-body -X POST https://example.com/deploy \
  -H "X-Deploy-Token: $CPANEL_DEPLOY_TOKEN"
```


Checked in this order by the deploy middleware:

1. **IP allowlist** (`CPANEL_DEPLOY_ALLOWED_IPS`) — if set, non-matching IPs get a `403` before auth is even checked.
2. **Rate limit** (if enabled) — exceeding it returns `429`.
3. **Webhook signature** — `X-Hub-Signature-256: sha256=...` (HMAC-SHA256 of the raw request body, GitHub-style) or `X-Gitlab-Token: <secret>`, compared with `hash_equals()`.
4. **Deploy token** — `X-Deploy-Token: {token}` header, compared with `hash_equals()`.

If none of these pass, the route returns `403`. If `CPANEL_DEPLOY_ENABLED` is `false`, every deploy route returns `404` rather than `403`, so an unconfigured install doesn't leak the fact that the routes exist.

### Customizing the pipeline

`pipeline.default_steps` accepts a mix of:

- **Named steps** (strings) — `sync-env`, `maintenance-down`, `optimize-clear`, `migrate`, `migrate-fresh`, `cache`, `queue-restart`, `storage-link`, `maintenance-up`, `optimize`.
- **Arbitrary artisan commands** — `'artisan:cache:clear'` runs `php artisan cache:clear`, or use an array to pass parameters: `['command' => 'queue:work', 'parameters' => ['--once' => true]]`.
- **Deployment step classes**: a class name implementing `Awaisjameel\LaravelCpanelHosting\Contracts\DeploymentStep`, resolved through the container. Use these for cacheable production configuration.
- **Closures**: for uncached configuration only; must return `bool` or a structured result.

```php
// config/cpanel-hosting.php
'pipeline' => [
    'default_steps' => [
        'sync-env',
        'maintenance-down',
        'artisan:cache:clear',
        'migrate',
        ['command' => 'db:seed', 'parameters' => ['--class' => 'ProductionSeeder', '--force' => true]],
        'cache',
        'storage-link',
        \App\Deployment\NotifyDeployment::class,
        'maintenance-up',
    ],
    'stop_on_failure' => true,
],
```

Custom classes implement `public function execute(): array|bool`. Closures and callable objects remain supported, but Laravel cannot serialize them in `config:cache`; do not place them in a pipeline that runs the `cache` step. An empty pipeline now fails without running implicit migrations. Callable structured results must contain a boolean `success`, string `message`, array `data`, and string-list `errors` when those optional fields are provided. Thrown callback exceptions become failed steps and honor `stop_on_failure`.

Environment synchronization takes effect on the **next Laravel process**. Configuration, service bindings and existing database connections in the current deployment request remain those loaded at boot. Prepare `.env` before starting a deployment when later steps must immediately use different credentials or connections. Rebuild cached configuration for subsequent requests.

The configured deployment prefix remains reachable through Laravel’s normal maintenance middleware, with deployment authentication still required. A failed pipeline leaves the current maintenance state intact so failures do not silently reopen the application. Hosting-level maintenance pages and Laravel pre-rendered maintenance responses can run before package boot; use SSH/control-panel recovery for those cases.

### Events

Listen for these to wire up notifications or auditing:

```php
use Awaisjameel\LaravelCpanelHosting\Events\{DeployStarting, DeployStepCompleted, DeployCompleted};

Event::listen(DeployStarting::class, function (DeployStarting $event) {
    // $event->steps, $event->ip
});

Event::listen(DeployStepCompleted::class, function (DeployStepCompleted $event) {
    // $event->step, $event->result
});

Event::listen(DeployCompleted::class, function (DeployCompleted $event) {
    // $event->success, $event->steps
});
```

### Facade

```php
use Awaisjameel\LaravelCpanelHosting\Facades\LaravelCpanelHosting;

LaravelCpanelHosting::isEnabled();    // bool
LaravelCpanelHosting::routePrefix();  // string
```

## Root hosting layout (cPanel `public_html`)

cPanel-style shared hosting typically serves everything under `public_html/` directly, but Laravel expects the web root to be `public/`. The installer's root stubs solve this without a symlink:

- **`index.php`** — a one-line passthrough (`require __DIR__.'/public/index.php'`) so the app root *is* your project root.
- **`.htaccess`**: serves files only from `public/`, protects environment/backup/dot files, supports storage assets and URL subdirectories, and redirects client `/public/` URLs without looping on internal rewrites. It includes `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy`. Configure CSP separately to match your application assets.

Deploy your Laravel project as-is to `public_html/` (or a subdirectory pointed at by your domain), run the installer once, and the app is servable without moving files around or fighting cPanel's document root.

## Security Notes

- **Keep `CPANEL_DEPLOY_TOKEN` secret** and rotate it immediately if it's ever exposed (logs, error trackers, a public repo).
- **Send deployment tokens in the `X-Deploy-Token` header**. Query-string tokens are rejected.
- **Restrict with `CPANEL_DEPLOY_ALLOWED_IPS`** whenever your CI/webhook provider publishes a stable IP range.
- **Never expose deploy routes with `APP_DEBUG=true`** in production — a failed step's stack trace should not be visible to the public internet.
- **`migrate-fresh` is destructive** — it drops every table. Only include it in your pipeline if you're certain you want that behavior on every deploy (most apps shouldn't).
- **Rate limits persist across requests** using the selected Laravel cache store (default `file`). For multiple servers, configure a shared store. Clearing that store resets its rate limits.
- **GitHub HMAC authenticates the raw payload. GitLab `X-Gitlab-Token` authenticates a shared header secret**; it does not sign the payload. Both require HTTPS. This package does not fetch code, select branches, or deduplicate webhook deliveries; configure provider delivery filters and serialize deployment triggers in CI.

## Testing

```bash
composer test
```

On Linux, exercise the Apache stub over real HTTP with `python3 scripts/test-apache.py` after installing `apache2-bin`. The test launches and stops its own unprivileged loopback server and checks root and subdirectory layouts.

Runs the Pest suite under `tests/` (feature tests for deploy routes/middleware, unit tests for `SyncEnvAction` and `StorageLinkAction`) via Orchestra Testbench. Also available:

```bash
composer analyse       # Larastan / PHPStan
composer format        # Laravel Pint
composer test-coverage # Pest with coverage
```

## Upgrading

See [MIGRATION.md](MIGRATION.md) for version 2 compatibility changes.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Contributing

Issues and pull requests are welcome at [github.com/awaisjameel/laravel-cpanel-hosting](https://github.com/awaisjameel/laravel-cpanel-hosting).

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for more information.
