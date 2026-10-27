# Upgrading to 2.0

- Change every mutating deployment request to POST. Health remains GET. Send `X-Deploy-Token` or provider webhook authentication; query-string tokens are no longer accepted. Update CI/webhook callers before upgrading.

- Require PHP 8.2–8.5 and Laravel 12 or 13. Laravel 13 needs PHP 8.3 or newer. Laravel 11 support is removed.
- Republish and review `cpanel-hosting.php`. Rate limiting now uses a persistent Laravel cache store, default `file`, and has a `rate_limit.store` setting / `CPANEL_DEPLOY_RATE_LIMIT_STORE` variable. Ensure that store is writable; use a shared store for multiple servers.
- Review the updated root stubs before running `cpanel-hosting:install --only-root --force`. Existing files receive unique backups. Keep `public/` as the document root where your host supports that layout; the root stub serves hosts that require a project-root document root.
- The Apache stub requires Apache 2.4 / compatible rewrite semantics and `AllowOverride All`. It serves public files only, protects `.env.*` and backups, preserves CGI authorization headers and supports URL subdirectories. Configure CSP in your application to match its assets.
- Environment sync rejects invalid syntax, missing required keys, source/target identity and symlink file targets before replacing the active file. Storage source and target must not overlap or replace application roots. Neither operation deletes the source.
- An empty pipeline is an error. String step names select named steps or `artisan:` commands. Callable results require a real boolean success value. Use class strings implementing `Contracts\DeploymentStep` for cacheable production pipelines; closures are incompatible with Laravel configuration caching.
- Both full pipelines and granular steps dispatch `DeployStepCompleted`. Audit listeners that assumed only full pipelines dispatched it. Callback exceptions become failed results and honor stop-on-failure.
- Sensitive Artisan parameters are redacted from responses; deployment logs contain step/status metadata rather than arbitrary command/callback data.
- Installer option conflicts or file failures return nonzero status. Interactive environment writes preserve quotes, backslashes and dollar signs and reject control characters. Existing multiline deploy values must be edited manually first.
- Synchronizing `.env` does not reload an already running Laravel process. Prepare changed connection credentials before deployment if they must be used by that same request.
