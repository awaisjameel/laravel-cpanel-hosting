<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting;

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Awaisjameel\LaravelCpanelHosting\Commands\InstallCpanelHostingCommand;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelCpanelHostingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-cpanel-hosting')
            ->hasConfigFile('cpanel-hosting')
            ->hasCommand(InstallCpanelHostingCommand::class);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(LaravelCpanelHosting::class, static fn (): LaravelCpanelHosting => new LaravelCpanelHosting);
        $this->app->singleton(SyncEnvAction::class);
        $this->app->singleton(StorageLinkAction::class);
    }

    public function bootingPackage(): void
    {
        $this->configureDeployLogChannel();
        $this->applyMysqlLegacyCompatibility();
        $this->registerStubPublishing();
        $this->registerDeployRoutes();
    }

    private function registerDeployRoutes(): void
    {
        if (! config('cpanel-hosting.enabled', false)) {
            return;
        }

        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        $this->loadRoutesFrom(dirname(__DIR__).'/routes/deploy.php');
    }

    private function configureDeployLogChannel(): void
    {
        $channelName = (string) config('cpanel-hosting.logging.channel', 'deploy');
        $channels = config('logging.channels', []);

        if (array_key_exists($channelName, $channels)) {
            return;
        }

        config([
            "logging.channels.{$channelName}" => [
                'driver' => 'single',
                'path' => storage_path('logs/cpanel-deploy.log'),
                'level' => (string) config('logging.level', 'info'),
                'replace_placeholders' => true,
            ],
        ]);
    }

    private function applyMysqlLegacyCompatibility(): void
    {
        if (! (bool) config('cpanel-hosting.mysql_legacy_compat.enabled', true)) {
            return;
        }

        $length = (int) config('cpanel-hosting.mysql_legacy_compat.length', 191);
        if ($length < 1) {
            return;
        }

        /** @var array<string, array<string, mixed>> $connections */
        $connections = config('database.connections', []);
        $applyToAllConnections = (bool) config('cpanel-hosting.mysql_legacy_compat.all_connections', false);

        if ($applyToAllConnections) {
            foreach ($connections as $connection) {
                $driver = (string) ($connection['driver'] ?? '');

                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    Schema::defaultStringLength($length);

                    return;
                }
            }

            return;
        }

        $defaultConnection = (string) config('database.default', 'mysql');
        $defaultDriver = (string) data_get($connections, "{$defaultConnection}.driver", '');

        if (in_array($defaultDriver, ['mysql', 'mariadb'], true)) {
            Schema::defaultStringLength($length);
        }
    }

    private function registerStubPublishing(): void
    {
        $stubPath = dirname(__DIR__).'/stubs';

        $this->publishes([
            "{$stubPath}/root-index.php.stub" => base_path('index.php'),
            "{$stubPath}/root-htaccess.stub" => base_path('.htaccess'),
        ], 'cpanel-hosting-stubs');
    }
}
