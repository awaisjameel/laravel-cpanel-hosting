<?php

namespace Awaisjameel\LaravelCpanelHosting;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Awaisjameel\LaravelCpanelHosting\Commands\LaravelCpanelHostingCommand;

class LaravelCpanelHostingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-cpanel-hosting')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_cpanel_hosting_table')
            ->hasCommand(LaravelCpanelHostingCommand::class);
    }
}
