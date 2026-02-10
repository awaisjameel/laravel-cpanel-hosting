<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Illuminate\Support\Facades\File;

it('copies storage directory when symlink is disabled', function (): void {
    $workspace = base_path('tests-temp/storage-link');
    $source = "{$workspace}/source";
    $target = "{$workspace}/public-storage";

    File::deleteDirectory($workspace);
    File::ensureDirectoryExists($source);
    File::put("{$source}/asset.txt", 'content');

    config()->set('cpanel-hosting.storage_link.prefer_symlink', false);
    config()->set('cpanel-hosting.storage_link.fallback_copy', true);
    config()->set('cpanel-hosting.storage_link.source', $source);
    config()->set('cpanel-hosting.storage_link.public_path', $target);

    $result = app(StorageLinkAction::class)->execute();

    expect($result['success'])->toBeTrue();
    expect(File::exists("{$target}/asset.txt"))->toBeTrue();

    File::deleteDirectory(base_path('tests-temp'));
});

it('fails when source directory does not exist', function (): void {
    config()->set('cpanel-hosting.storage_link.source', base_path('tests-temp/no-source'));
    config()->set('cpanel-hosting.storage_link.public_path', base_path('tests-temp/no-target'));
    config()->set('cpanel-hosting.storage_link.prefer_symlink', false);
    config()->set('cpanel-hosting.storage_link.fallback_copy', true);

    $result = app(StorageLinkAction::class)->execute();

    expect($result['success'])->toBeFalse();
});
