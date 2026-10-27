<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Facades\LaravelCpanelHosting;
use Awaisjameel\LaravelCpanelHosting\LaravelCpanelHostingServiceProvider;
use Illuminate\Database\Schema\Builder;

it('exposes typed facade accessors', function (): void {
    expect(LaravelCpanelHosting::isEnabled())->toBeTrue()->and(LaravelCpanelHosting::routePrefix())->toBe('deploy');
});

it('applies legacy string lengths only for eligible database drivers', function (): void {
    $original = Builder::$defaultStringLength;
    $originalConnections = config('database.connections');
    $originalDefault = config('database.default');
    try {
        config()->set('database.default', 'primary');
        config()->set('database.connections', ['primary' => ['driver' => 'mysql']]);
        config()->set('cpanel-hosting.mysql_legacy_compat.length', 190);
        (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage();
        expect(Builder::$defaultStringLength)->toBe(190);
        Builder::$defaultStringLength = 255;
        config()->set('database.connections', ['primary' => ['driver' => 'sqlite'], 'secondary' => ['driver' => 'mariadb']]);
        (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage();
        expect(Builder::$defaultStringLength)->toBe(255);
        config()->set('cpanel-hosting.mysql_legacy_compat.all_connections', true);
        (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage();
        expect(Builder::$defaultStringLength)->toBe(190);
        Builder::$defaultStringLength = 255;
        config()->set('cpanel-hosting.mysql_legacy_compat.enabled', false);
        (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage();
        expect(Builder::$defaultStringLength)->toBe(255);
    } finally {
        Builder::$defaultStringLength = $original;
        config()->set('database.connections', $originalConnections);
        config()->set('database.default', $originalDefault);
    }
});

it('rejects empty or wildcard deployment prefixes', function (string $prefix): void {
    config()->set('cpanel-hosting.route_prefix', $prefix);
    expect(fn () => (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage())->toThrow(InvalidArgumentException::class);
})->with(['', '*', 'deploy/*']);

it('registers a dedicated log channel without overriding an application channel', function (): void {
    config()->set('cpanel-hosting.logging.channel', 'isolated-deploy');
    $provider = new LaravelCpanelHostingServiceProvider(app());
    $provider->bootingPackage();
    expect(config('logging.channels.isolated-deploy.driver'))->toBe('single');
    config()->set('logging.channels.isolated-deploy', ['driver' => 'null']);
    $provider->bootingPackage();
    expect(config('logging.channels.isolated-deploy.driver'))->toBe('null');
});
