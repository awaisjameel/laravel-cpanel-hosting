<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Awaisjameel\LaravelCpanelHosting\Support\Paths;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->workspace = base_path('tests-temp/storage-'.Str::uuid());
    File::ensureDirectoryExists($this->workspace.'/source/nested');
    File::put($this->workspace.'/source/nested/asset.txt', 'source asset');
    config()->set('cpanel-hosting.storage_link.source', $this->workspace.'/source');
    config()->set('cpanel-hosting.storage_link.public_path', $this->workspace.'/target');
});

afterEach(function (): void {
    (new Filesystem)->remove($this->workspace);
});

it('replaces an old public tree with a fully prepared copy and removes stale files', function (): void {
    File::ensureDirectoryExists($this->workspace.'/target');
    File::put($this->workspace.'/target/stale.txt', 'stale');
    config()->set('cpanel-hosting.storage_link.prefer_symlink', false);
    $result = app(StorageLinkAction::class)->execute();
    expect($result['success'])->toBeTrue()->and($result['data']['mode'])->toBe('copy')
        ->and(File::get($this->workspace.'/target/nested/asset.txt'))->toBe('source asset')
        ->and(File::exists($this->workspace.'/target/stale.txt'))->toBeFalse()
        ->and(File::glob($this->workspace.'/.cpanel-*'))->toBe([]);
});

it('uses symlinks when available and preserves the source on repeated calls', function (): void {
    $result = app(StorageLinkAction::class)->execute();
    expect($result['success'])->toBeTrue()->and(File::get($this->workspace.'/target/nested/asset.txt'))->toBe('source asset');
    if ($result['data']['mode'] === 'symlink') {
        expect(app(StorageLinkAction::class)->execute()['message'])->toBe('Storage already linked.');
    } else {
        expect($result['errors'])->toContain('Symlink creation was unavailable; used directory copy.');
    }
    expect(File::get($this->workspace.'/source/nested/asset.txt'))->toBe('source asset');
});

it('fails safely when both storage methods are disabled', function (): void {
    config()->set('cpanel-hosting.storage_link.prefer_symlink', false);
    config()->set('cpanel-hosting.storage_link.fallback_copy', false);
    expect(app(StorageLinkAction::class)->execute()['success'])->toBeFalse()
        ->and(File::exists($this->workspace.'/source/nested/asset.txt'))->toBeTrue();
});

it('rejects ancestor and descendant storage targets', function (string $relative): void {
    config()->set('cpanel-hosting.storage_link.public_path', $this->workspace.$relative);
    expect(app(StorageLinkAction::class)->execute()['success'])->toBeFalse()
        ->and(File::get($this->workspace.'/source/nested/asset.txt'))->toBe('source asset');
})->with(['ancestor' => [''], 'descendant' => ['/source/nested/public']]);

it('rejects application roots as storage targets', function (): void {
    foreach ([base_path(), public_path(), storage_path()] as $target) {
        config()->set('cpanel-hosting.storage_link.public_path', $target);
        expect(app(StorageLinkAction::class)->execute()['success'])->toBeFalse();
    }
});

it('resolves missing target ancestors while rejecting remote and empty paths', function (): void {
    $missing = $this->workspace.'/not-yet/deeper/target';
    expect(Paths::same(Paths::destination($missing), $missing))->toBeTrue()
        ->and(fn () => Paths::resolve('https://example.com/file', base_path()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Paths::resolve('', base_path()))->toThrow(InvalidArgumentException::class);
});

it('validates boolean and integer settings instead of silently changing their meaning', function (): void {
    config()->set('cpanel-hosting.pipeline.stop_on_failure', 'false');
    config()->set('cpanel-hosting.rate_limit.max_attempts', '2');
    expect(Settings::flag('pipeline.stop_on_failure', true))->toBeFalse()
        ->and(Settings::positiveInteger('rate_limit.max_attempts', 30))->toBe(2);
    config()->set('cpanel-hosting.pipeline.stop_on_failure', 'perhaps');
    config()->set('cpanel-hosting.rate_limit.max_attempts', 0);
    config()->set('cpanel-hosting.route_prefix', []);
    expect(fn () => Settings::flag('pipeline.stop_on_failure', true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Settings::positiveInteger('rate_limit.max_attempts', 30))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Settings::text('route_prefix'))->toThrow(InvalidArgumentException::class);
});
