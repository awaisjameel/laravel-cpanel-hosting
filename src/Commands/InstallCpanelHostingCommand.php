<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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
    }

    private function publishConfig(bool $force): void
    {
        $this->call('vendor:publish', [
            '--tag' => 'cpanel-hosting-config',
            '--force' => $force,
        ]);
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
            $this->components->error("Stub missing: {$source}");

            return;
        }

        if (File::exists($target) && ! $force) {
            $this->components->twoColumnDetail($target, 'Skipped (exists, use --force to overwrite)');

            return;
        }

        if (File::exists($target)) {
            $backupPath = $this->backupFile($target);
            $this->components->twoColumnDetail($target, "Backed up to {$backupPath}");
        }

        File::copy($source, $target);
        $this->components->twoColumnDetail($target, 'Installed');
    }

    private function backupFile(string $path): string
    {
        $backupPath = $path.'.backup.'.date('YmdHis');
        File::copy($path, $backupPath);

        return $backupPath;
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

        $routePrefix = (string) $this->ask('Deploy route prefix', (string) config('cpanel-hosting.route_prefix', 'deploy'));
        $enableDeploy = $this->confirm('Enable deploy routes now?', false);

        $this->upsertEnvValue(base_path('.env'), 'CPANEL_DEPLOY_TOKEN', $token);
        $this->upsertEnvValue(base_path('.env'), 'CPANEL_DEPLOY_PREFIX', $routePrefix);
        $this->upsertEnvValue(base_path('.env'), 'CPANEL_DEPLOY_ENABLED', $enableDeploy ? 'true' : 'false');
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
            'CPANEL_SYNC_ENV_SOURCE=.env.server',
            'CPANEL_SYNC_ENV_TARGET=.env',
            'CPANEL_STORAGE_LINK_PREFER_SYMLINK=true',
            'CPANEL_STORAGE_LINK_FALLBACK_COPY=true',
            'CPANEL_MYSQL_LEGACY_COMPAT=true',
            'CPANEL_DEPLOY_LOG_CHANNEL=deploy',
        ];

        if (! File::exists($file)) {
            File::put($file, implode(PHP_EOL, $lines).PHP_EOL);

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

        File::put($file, $content.PHP_EOL);
    }

    private function upsertEnvValue(string $file, string $key, string $value): void
    {
        $line = "{$key}={$value}";
        $content = File::exists($file) ? File::get($file) : '';
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            $content = (string) preg_replace($pattern, $line, $content);
        } else {
            $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
        }

        File::put($file, $content);
    }
}
