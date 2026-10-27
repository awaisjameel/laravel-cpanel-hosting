# Laravel cPanel Hosting architecture and implementation requirements

Read this file before changing the package. Keep runtime behavior, tests,
configuration, README, migration notes and release archives consistent.

## Scope and support

The package supports project-root hosting, authenticated deployment actions,
environment synchronization, public storage links/copies, deployment lifecycle
events and optional legacy MySQL index lengths. It does not fetch source code or
implement a general CI system. GitHub payload HMAC and GitLab header-token
verification are supported; do not claim Bitbucket-specific authentication.

Support maintained PHP 8.2–8.5 and Laravel 12–13. Laravel 13 requires PHP 8.3+.
Use the latest dependencies compatible with each maintained combination. Declare
direct dependencies used by runtime source. Do not reintroduce skeleton scaffolds,
unused tools, duplicate result builders or process-local security state.

## Ownership

- `LaravelCpanelHostingServiceProvider`: package discovery, configuration, installer,
  log channel, optional schema defaults and deployment routes. Register routes for
  production console boot so Laravel route caching includes them.
- `Http/Middleware/EnsureDeployTokenIsValid`: enabled flag, IP allowlist, persistent
  cache-backed rate limit, constant-time deployment/webhook authentication.
- `Http/Controllers/DeployController`: HTTP adaptation and the public JSON envelope.
- `Actions/DeployAction`: named/custom step dispatch, Artisan execution, strict
  result validation, pipeline stop/continue policy, lifecycle events and safe logs.
- `Actions/SyncEnvAction`: read/parse/validate source before changing the active
  environment, private unique backups and atomic replacement.
- `Actions/StorageLinkAction`: validate local nonoverlapping paths and protected
  roots; prepare symlink or copy before replacing the previous public tree; restore
  the old tree if promotion fails. Never delete the source.
- `Support/StepResult`: shared `{success, message, data, errors}` result shape.
- `Support/Paths`, `AtomicFiles`, `EnvironmentFile`, `Settings`: shared validation,
  local filesystem operations, literal environment encoding and typed settings.
- `Contracts/DeploymentStep`: container-resolved custom steps represented by class
  strings for cacheable Laravel configuration. Closures work only with uncached
  configuration. Never silently substitute a default pipeline for an empty one.
- `Commands/InstallCpanelHostingCommand`: publish config, install reviewed root
  stubs, safely configure environment, append missing example keys. Existing files
  are skipped unless forced; replacements receive unique backups. Failures return
  nonzero exit codes. `--only-root` and `--only-config` are mutually exclusive.

## Behavior and security

Read the endpoint/authentication contract in README before changing it. Ask the
owner before changing compatibility policy. Mutating actions use POST only; health uses GET. Query-string authentication is rejected. Routes bypass session/CSRF middleware
and require package authentication. Disabled routes return 404. Never log secrets,
arbitrary callback data or raw command output. Redact sensitive Artisan parameters
and their values in returned output. Do not include environment parser excerpts in
errors or logs: they can contain credentials.

Use Laravel's cache-backed limiter with a file store by default, without requiring
a database table. Rate-limited responses include `Retry-After`. An application with
multiple servers must choose a shared cache store. Deployment events are dispatched
once by their execution owner. Thrown custom-step exceptions become failed results;
listener exceptions retain normal Laravel behavior. Failed pipelines preserve the
current maintenance state. Only the configured literal deployment prefix bypasses
normal Laravel maintenance middleware; package authentication still applies.

Environment replacement affects subsequent Laravel processes. Do not imply that
it refreshes bindings, cached configuration or database connections already loaded
in the current request. Document pre-rendered/hosting maintenance recovery limits.

Filesystem mutations must stay within validated intended targets. Handle Windows
absolute paths as well as Linux paths. Resolve existing ancestors to detect overlap
through parent symlinks. Do not follow the final destination symlink during safety
checks, and do not replace file symlinks when writing secrets. Stage new files and
trees privately; remove only staging paths owned by the operation.

The Apache 2.4 root stub must serve files from `public/`, preserve CGI authorization
headers, avoid internal rewrite/redirect loops, support URL subdirectories and public
storage assets, deny environment/backup/dot files, and preserve explicit AutoSSL
challenge files. Apply CSP at the application level. A public document root remains
preferred where the host permits it. The root PHP stub simply requires public/index.php.

## Verification and releases

Use strict PHP types, Laravel Pint, PHPStan level 8 and meaningful Pest regressions.
Test full pipelines, individual routes, auth/tampering, throttling across middleware
instances, route caching, maintenance recovery, installer output/failures, atomic
writes/backups, overlapping paths and storage replacement. Mock destructive Artisan
commands in tests. Actual consumer tests must use disposable applications and paths.
Run all seven maintained PHP/Laravel combinations and representative lowest versions.
Run `python3 scripts/test-apache.py` against an isolated unprivileged Apache server;
static rewrite inspection does not establish HTTP behavior.

Verify production Composer archives with no-dev Laravel consumers, package discovery,
config publishing and actual safe filesystem/HTTP operations. Keep development-only
files, vendor trees, caches and logs out of release archives. Update changelog and
migration notes using semantic versioning. Do not publish or push without authority.
Do not claim hosted cPanel, LiteSpeed, CI or live-account validation without evidence.
Before committing, reread relevant sections and verify architecture, edge cases,
compatibility and documentation consistency.
