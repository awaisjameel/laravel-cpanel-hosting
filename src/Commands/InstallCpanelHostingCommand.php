<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Commands;

use Awaisjameel\LaravelCpanelHosting\Support\AtomicFiles;
use Awaisjameel\LaravelCpanelHosting\Support\EnvironmentFile;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class InstallCpanelHostingCommand extends Command
{
    protected $signature = 'cpanel-hosting:install
        {--force : Overwrite existing root files and config}
        {--only-config : Publish only config}
        {--only-root : Install only root index/.htaccess stubs}';

    protected $description = 'Install and configure laravel-cpanel-hosting.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $onlyConfig = (bool) $this->option('only-config');
        $onlyRoot = (bool) $this->option('only-root');

        if ($onlyConfig && $onlyRoot) {
            $this->components->error('--only-config and --only-root cannot be combined.');

            return self::INVALID;
        }

        try {
            if (! $onlyRoot) {
                $this->publishConfig($force);
            }

            if (! $onlyConfig) {
                $this->installRootStubs($force);
            }

            if (! $this->input->isInteractive()) {
                $this->appendEnvExampleKeys();

                return self::SUCCESS;
            }

            $this->configureEnvironment();
            $this->appendEnvExampleKeys();

            $this->components->info('laravel-cpanel-hosting installed successfully.');
            $this->line('Set CPANEL_DEPLOY_ENABLED=true when you are ready to expose deploy routes.');
            $this->line('Prefer sending your deploy token in the X-Deploy-Token header.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Installation failed. Check filesystem permissions and the application log.');

            return self::FAILURE;
        }
    }

    private function publishConfig(bool $force): void
    {
        if ($this->call('vendor:publish', [
            '--tag' => 'cpanel-hosting-config',
            '--force' => $force,
        ]) !== self::SUCCESS) {
            throw new RuntimeException('Publishing the package configuration failed.');
        }
    }

    private function installRootStubs(bool $force): void
    {
        $stubDir = dirname(__DIR__, 2).'/stubs';

        $this->copyRootFile("{$stubDir}/root-index.php.stub", base_path('index.php'), $force);
        $this->copyRootFile("{$stubDir}/root-htaccess.stub", base_path('.htaccess'), $force);
    }

    private function copyRootFile(string $source, string $target, bool $force): void
    {
        if (! File::exists($source)) {
            throw new RuntimeException("Stub missing: {$source}");
        }

        if (File::exists($target) && ! $force) {
            $this->components->twoColumnDetail($target, 'Skipped (exists, use --force to overwrite)');

            return;
        }

        if (File::exists($target)) {
            $backupPath = AtomicFiles::backup($target);
            $this->components->twoColumnDetail($target, "Backed up to {$backupPath}");
        }

        AtomicFiles::write($target, File::get($source), 0644);
        $this->components->twoColumnDetail($target, 'Installed');
    }

    private function configureEnvironment(): void
    {
        if (! $this->confirm('Update your .env deploy values now?', true)) {
            return;
        }

        $token = $this->secret('Deploy token (leave empty to auto-generate)');
        if (! is_string($token) || trim($token) === '') {
            $token = Str::random(64);
        }

        $routePrefix = $this->ask('Deploy route prefix', Settings::text('route_prefix', 'deploy'));
        if (! is_string($routePrefix) || preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/', $routePrefix) !== 1) {
            throw new RuntimeException('The deployment prefix must contain literal path segments.');
        }
        $enableDeploy = $this->confirm('Enable deploy routes now?', false);

        EnvironmentFile::update(base_path('.env'), [
            'CPANEL_DEPLOY_TOKEN' => $token,
            'CPANEL_DEPLOY_PREFIX' => $routePrefix,
            'CPANEL_DEPLOY_ENABLED' => $enableDeploy ? 'true' : 'false',
        ]);
    }

    private function appendEnvExampleKeys(): void
    {
        $file = base_path('.env.example');
        $lines = [
            'CPANEL_DEPLOY_ENABLED=false',
            'CPANEL_DEPLOY_TOKEN=',
            'CPANEL_DEPLOY_WEBHOOK_SECRET=',
            'CPANEL_DEPLOY_PREFIX=deploy',
            'CPANEL_DEPLOY_ALLOWED_IPS=',
            'CPANEL_DEPLOY_STOP_ON_FAILURE=true',
            'CPANEL_DEPLOY_RATE_LIMIT_ENABLED=false',
            'CPANEL_DEPLOY_RATE_LIMIT_STORE=file',
            'CPANEL_DEPLOY_RATE_LIMIT_MAX_ATTEMPTS=30',
            'CPANEL_DEPLOY_RATE_LIMIT_DECAY_SECONDS=60',
            'CPANEL_SYNC_ENV_SOURCE=.env.server',
            'CPANEL_SYNC_ENV_TARGET=.env',
            'CPANEL_SYNC_ENV_BACKUP=true',
            'CPANEL_STORAGE_LINK_PREFER_SYMLINK=true',
            'CPANEL_STORAGE_LINK_FALLBACK_COPY=true',
            'CPANEL_STORAGE_LINK_SOURCE=app/public',
            'CPANEL_STORAGE_LINK_PUBLIC_PATH=storage',
            'CPANEL_MYSQL_LEGACY_COMPAT=true',
            'CPANEL_MYSQL_LEGACY_LENGTH=191',
            'CPANEL_MYSQL_LEGACY_ALL_CONNECTIONS=false',
            'APP_MAINTENANCE_SECRET=',
            'CPANEL_DEPLOY_LOG_CHANNEL=deploy',
        ];

        if (! File::exists($file)) {
            AtomicFiles::write($file, implode(PHP_EOL, $lines).PHP_EOL, 0644);

            return;
        }

        $content = File::get($file);
        $content = rtrim($content);

        foreach ($lines as $line) {
            $key = Str::before($line, '=');
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $content) === 1) {
                continue;
            }

            $content .= PHP_EOL.$line;
        }

        AtomicFiles::write($file, $content.PHP_EOL, 0644);
    }
}
