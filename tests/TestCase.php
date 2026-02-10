<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Tests;

use Awaisjameel\LaravelCpanelHosting\LaravelCpanelHostingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelCpanelHostingServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cpanel-hosting.enabled', true);
        $app['config']->set('cpanel-hosting.token', 'test-deploy-token');
        $app['config']->set('cpanel-hosting.route_prefix', 'deploy');
        $app['config']->set('logging.channels.deploy', [
            'driver' => 'single',
            'path' => storage_path('logs/deploy-test.log'),
        ]);
    }
}
