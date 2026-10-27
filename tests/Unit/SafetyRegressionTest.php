<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Awaisjameel\LaravelCpanelHosting\LaravelCpanelHostingServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->workspace = base_path('tests-temp/safety-'.Str::uuid());
    File::ensureDirectoryExists($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('preserves the current environment when the source fails required-key validation', function (): void {
    $source = $this->workspace.'/.env.server';
    $target = $this->workspace.'/.env';
    File::put($source, "APP_ENV=production\n");
    File::put($target, "APP_KEY=existing-key\n");
    config()->set('cpanel-hosting.sync_env.source', $source);
    config()->set('cpanel-hosting.sync_env.target', $target);
    config()->set('cpanel-hosting.sync_env.required_keys', ['APP_KEY']);

    expect(app(SyncEnvAction::class)->execute()['success'])->toBeFalse();
    expect(File::get($target))->toBe("APP_KEY=existing-key\n");
});

it('refuses to erase its source when storage source and target are identical', function (): void {
    $source = $this->workspace.'/source';
    File::ensureDirectoryExists($source);
    File::put($source.'/asset.txt', 'preserve me');
    config()->set('cpanel-hosting.storage_link.source', $source);
    config()->set('cpanel-hosting.storage_link.public_path', $source);
    config()->set('cpanel-hosting.storage_link.prefer_symlink', false);

    expect(app(StorageLinkAction::class)->execute()['success'])->toBeFalse();
    expect(File::exists($source.'/asset.txt'))->toBeTrue();
});

it('registers deploy routes when preparing a production console route cache', function (): void {
    app('router')->setRoutes(new RouteCollection);
    app()->instance('env', 'production');
    try {
        (new LaravelCpanelHostingServiceProvider(app()))->bootingPackage();
        expect(collect(app('router')->getRoutes())->contains(fn ($route) => $route->uri() === 'deploy/health'))->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
});
