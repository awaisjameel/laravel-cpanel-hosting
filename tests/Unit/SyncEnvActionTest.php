<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Illuminate\Support\Facades\File;

it('syncs environment file from source to target', function (): void {
    $workspace = base_path('tests-temp/sync-env');
    $source = "{$workspace}/.env.server";
    $target = "{$workspace}/.env";

    File::deleteDirectory($workspace);
    File::ensureDirectoryExists($workspace);
    File::put($source, "APP_KEY=base64:123456\nAPP_ENV=testing\n");

    config()->set('cpanel-hosting.sync_env.source', $source);
    config()->set('cpanel-hosting.sync_env.target', $target);
    config()->set('cpanel-hosting.sync_env.required_keys', ['APP_KEY']);

    $result = app(SyncEnvAction::class)->execute();

    expect($result['success'])->toBeTrue();
    expect(File::exists($target))->toBeTrue();

    File::deleteDirectory(base_path('tests-temp'));
});

it('fails when source env file does not exist', function (): void {
    config()->set('cpanel-hosting.sync_env.source', base_path('tests-temp/missing.env'));
    config()->set('cpanel-hosting.sync_env.target', base_path('tests-temp/.env'));

    $result = app(SyncEnvAction::class)->execute();

    expect($result['success'])->toBeFalse();
});
