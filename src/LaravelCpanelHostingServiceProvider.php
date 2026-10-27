<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting;

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Awaisjameel\LaravelCpanelHosting\Commands\InstallCpanelHostingCommand;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
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
        if (! Settings::flag('enabled', false)) {
            return;
        }

        $prefix = trim(Settings::text('route_prefix', 'deploy'), '/');
        if ($prefix === '' || preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException('The deployment prefix must contain literal path segments.');
        }
        PreventRequestsDuringMaintenance::except([$prefix, $prefix.'/*']);
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/deploy.php');
    }

    private function configureDeployLogChannel(): void
    {
        $channelName = Settings::text('logging.channel', 'deploy');
        $channels = config('logging.channels', []);

        if (is_array($channels) && array_key_exists($channelName, $channels)) {
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
        if (! Settings::flag('mysql_legacy_compat.enabled', true)) {
            return;
        }

        $length = Settings::positiveInteger('mysql_legacy_compat.length', 191);

        /** @var array<string, array<string, mixed>> $connections */
        $connections = config('database.connections', []);
        $applyToAllConnections = Settings::flag('mysql_legacy_compat.all_connections', false);

        if ($applyToAllConnections) {
            foreach ($connections as $connection) {
                $driver = (string) ($connection['driver'] ?? '');

                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    Builder::defaultStringLength($length);

                    return;
                }
            }

            return;
        }

        $defaultConnection = (string) config('database.default', 'mysql');
        $defaultDriver = (string) data_get($connections, "{$defaultConnection}.driver", '');

        if (in_array($defaultDriver, ['mysql', 'mariadb'], true)) {
            Builder::defaultStringLength($length);
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
