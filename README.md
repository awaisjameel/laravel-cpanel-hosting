# Laravel cPanel Hosting

A robust, secure, and production-ready Laravel package that makes deploying Laravel applications on **cPanel / shared hosting** painless and professional.

Shared hosting doesn't give you SSH-driven CI/CD, so this package exposes a small set of authenticated HTTP endpoints that let a webhook (GitHub, GitLab, Bitbucket, or your own script) drive a deployment: pull the latest code (outside the scope of this package), then hit `/deploy` to sync the environment, run migrations, rebuild caches, relink storage, and flip maintenance mode — all guarded by a token, an IP allowlist, and optional rate limiting.

## Features

- **Secure deploy endpoints** — token (`X-Deploy-Token` header or `?token=`) or webhook signature (`X-Hub-Signature-256` / `X-Gitlab-Token`) authentication, optional IP allowlist, optional in-memory rate limiting.
- **Configurable deploy pipeline** — a single `GET /deploy` runs an ordered list of steps (strings, artisan command arrays, or closures) and stops or continues on failure per your config.
- **Granular endpoints** — every pipeline step is also its own route, so you can call `storage-link` or `migrate` on their own.
- **`.env` sync** — copies a server-side env file (e.g. `.env.server`) over `.env`, with automatic timestamped backups and required-key validation (`APP_KEY` by default).
- **Storage link fallback** — tries `symlink()` first, and transparently falls back to a recursive directory copy (with correct file/directory permissions) on hosts where `symlink()` is disabled.
- **Installer command** — publishes config, root `index.php` passthrough and hardened `.htaccess` stubs for cPanel's `public_html` layout, and (interactively) writes your deploy token/prefix straight into `.env`.
- **Dedicated deploy log channel** — auto-registered if you haven't already defined one, so deploy activity doesn't get lost in `laravel.log`.
- **Deploy lifecycle events** — `DeployStarting`, `DeployStepCompleted`, `DeployCompleted` for hooking in notifications (Slack, email, etc.).
- **MySQL legacy compatibility** — automatically applies `Schema::defaultStringLength()` for older MySQL/MariaDB versions still common on shared hosting (`utf8mb4` + short index key limits).

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x

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
2. Install `index.php` and `.htaccess` at your project root (backing up any existing files first), so the app root can be pointed at your Laravel project directory directly instead of `public/` on cPanel.
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
| `rate_limit.*` | disabled | A lightweight in-memory (per-request-lifetime) limiter — see [Security Notes](#security-notes) for why this isn't a substitute for a real throttle. |
| `sync_env.source` / `target` | `.env.server` / `.env` | Paths are resolved relative to the app base path unless absolute. |
| `sync_env.backup` | `true` | Writes `{target}.backup.{YmdHis}` before overwriting. |
| `sync_env.required_keys` | `['APP_KEY']` | Sync fails if any of these keys are absent from the synced file. Edit the published config to add more (e.g. `DB_PASSWORD`). |
| `storage_link.prefer_symlink` | `true` | Try `symlink()` first. |
| `storage_link.fallback_copy` | `true` | If `symlink()` is unavailable or fails, recursively copy instead (with `0755`/`0644` permissions applied). |
| `storage_link.source` / `public_path` | `app/public` / `storage` | Resolved via `storage_path()` / `public_path()` unless absolute. |
| `mysql_legacy_compat.enabled` | `true` | Calls `Schema::defaultStringLength()` on boot when the active connection driver is `mysql`/`mariadb`. |
| `mysql_legacy_compat.all_connections` | `false` | When `true`, checks all configured connections instead of just `database.default`. |
| `pipeline.default_steps` | see below | The ordered list of steps `GET /deploy` runs. |
| `pipeline.stop_on_failure` | `true` | Stop the pipeline at the first failed step, or run all steps and report an overall failure. |
| `maintenance.secret` | `null` | Passed as `--secret` to `php artisan down`, letting you bypass the maintenance page via `/?secret=...`. |
| `logging.channel` | `deploy` | Auto-registered as a `single` driver writing to `storage/logs/cpanel-deploy.log` if you haven't defined this channel yourself. |

## Endpoints

Once `CPANEL_DEPLOY_ENABLED=true`, routes are registered under `CPANEL_DEPLOY_PREFIX` (default `deploy`):

| Method & Path | Purpose |
|---|---|
| `GET /deploy` | Runs the full pipeline (`pipeline.default_steps`). |
| `GET /deploy/sync-env` | Copies `sync_env.source` over `sync_env.target`. |
| `GET /deploy/clear` | `optimize:clear`. |
| `GET /deploy/migrate` | `migrate --force`. |
| `GET /deploy/migrate-fresh` | `migrate:fresh --force` — **destructive**, drops all tables. |
| `GET /deploy/cache` | `config:cache`, `route:cache`, `view:cache`, `event:cache`. |
| `GET /deploy/queue-restart` | `queue:restart`. |
| `GET /deploy/storage-link` | Symlink (or copy-fallback) `storage/app/public` into `public/storage`. |
| `GET /deploy/maintenance-down` | `down --retry=60`, plus `--secret` if `maintenance.secret` is set. |
| `GET /deploy/maintenance-up` | `up`. |
| `GET /deploy/optimize` | `optimize`. |
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

Checked in this order by the deploy middleware:

1. **IP allowlist** (`CPANEL_DEPLOY_ALLOWED_IPS`) — if set, non-matching IPs get a `403` before auth is even checked.
2. **Rate limit** (if enabled) — exceeding it returns `429`.
3. **Webhook signature** — `X-Hub-Signature-256: sha256=...` (HMAC-SHA256 of the raw request body, GitHub-style) or `X-Gitlab-Token: <secret>`, compared with `hash_equals()`.
4. **Deploy token** — `X-Deploy-Token: {token}` header (preferred) or `?token={token}` query string, compared with `hash_equals()`.

If none of these pass, the route returns `403`. If `CPANEL_DEPLOY_ENABLED` is `false`, every deploy route returns `404` rather than `403`, so an unconfigured install doesn't leak the fact that the routes exist.

### Customizing the pipeline

`pipeline.default_steps` accepts a mix of:

- **Named steps** (strings) — `sync-env`, `maintenance-down`, `optimize-clear`, `migrate`, `migrate-fresh`, `cache`, `queue-restart`, `storage-link`, `maintenance-up`, `optimize`.
- **Arbitrary artisan commands** — `'artisan:cache:clear'` runs `php artisan cache:clear`, or use an array to pass parameters: `['command' => 'queue:work', 'parameters' => ['--once' => true]]`.
- **Closures** — for anything custom; must return `bool` or a `['success' => bool, 'message' => string, 'data' => array, 'errors' => array]` shape.

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
        fn () => Http::post('https://hooks.slack.com/...', ['text' => 'Deploy finished!'])->successful(),
        'maintenance-up',
    ],
    'stop_on_failure' => true,
],
```

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
- **`.htaccess`** — blocks direct access to sensitive files (`.env`, `composer.json/.lock`, `phpunit.xml`, `artisan`) and internal framework directories (`app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `storage`, `tests`, `vendor`), rewrites `/public/...` requests away, and serves everything else from `public/` — with baseline security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, a permissive `Content-Security-Policy` you should tighten per app).

Deploy your Laravel project as-is to `public_html/` (or a subdirectory pointed at by your domain), run the installer once, and the app is servable without moving files around or fighting cPanel's document root.

## Security Notes

- **Keep `CPANEL_DEPLOY_TOKEN` secret** and rotate it immediately if it's ever exposed (logs, error trackers, a public repo).
- **Prefer the header token** (`X-Deploy-Token`) over the query-string token — query strings tend to end up in access logs and browser history.
- **Restrict with `CPANEL_DEPLOY_ALLOWED_IPS`** whenever your CI/webhook provider publishes a stable IP range.
- **Never expose deploy routes with `APP_DEBUG=true`** in production — a failed step's stack trace should not be visible to the public internet.
- **`migrate-fresh` is destructive** — it drops every table. Only include it in your pipeline if you're certain you want that behavior on every deploy (most apps shouldn't).
- **The built-in rate limiter is process-local, in-memory state** (a static array), not a shared cache-backed limiter — it resets on every new PHP-FPM/CLI process and offers no protection across concurrent requests or multiple app servers. Treat it as a minor speed bump, not a defense against brute force; for real protection, pair the deploy token with `CPANEL_DEPLOY_ALLOWED_IPS` or a firewall rule at the host level.
- **Webhook signatures beat static tokens** where the provider supports them (GitHub/GitLab) — the payload is authenticated, not just a shared secret in a header.

## Testing

```bash
composer test
```

Runs the Pest suite under `tests/` (feature tests for deploy routes/middleware, unit tests for `SyncEnvAction` and `StorageLinkAction`) via Orchestra Testbench. Also available:

```bash
composer analyse       # Larastan / PHPStan
composer format        # Laravel Pint
composer test-coverage # Pest with coverage
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Contributing

Issues and pull requests are welcome at [github.com/awaisjameel/laravel-cpanel-hosting](https://github.com/awaisjameel/laravel-cpanel-hosting).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
