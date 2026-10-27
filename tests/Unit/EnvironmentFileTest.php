<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Awaisjameel\LaravelCpanelHosting\Support\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->workspace = base_path('tests-temp/env-'.Str::uuid());
    File::ensureDirectoryExists($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('round trips literal environment values and retains a private unique backup', function (): void {
    $path = $this->workspace.'/.env';
    File::put($path, "APP_NAME=Existing\nexport CPANEL_DEPLOY_TOKEN=old\n");
    $token = 'quotes " single\' slash\\ dollar${APP_NAME} $1 # hash';
    EnvironmentFile::update($path, ['CPANEL_DEPLOY_TOKEN' => $token, 'CPANEL_DEPLOY_PREFIX' => 'operations/deploy']);
    expect(Dotenv::parse(File::get($path)))->toMatchArray(['APP_NAME' => 'Existing', 'CPANEL_DEPLOY_TOKEN' => $token, 'CPANEL_DEPLOY_PREFIX' => 'operations/deploy']);
    EnvironmentFile::update($path, ['CPANEL_DEPLOY_TOKEN' => 'replacement']);
    expect(File::glob($path.'.backup.*'))->toHaveCount(2);
});

it('rejects control characters without changing the current file', function (): void {
    $path = $this->workspace.'/.env';
    File::put($path, "APP_KEY=old\n");
    expect(fn () => EnvironmentFile::update($path, ['CPANEL_DEPLOY_TOKEN' => "injection\nAPP_DEBUG=true"]))->toThrow(InvalidArgumentException::class);
    expect(File::get($path))->toBe("APP_KEY=old\n")->and(File::glob($path.'.backup.*'))->toBe([]);
});

it('preserves existing multiline values instead of corrupting them during replacement', function (): void {
    $path = $this->workspace.'/.env';
    $content = "CPANEL_DEPLOY_TOKEN=\"line1\nline2\"\n";
    File::put($path, $content);
    expect(fn () => EnvironmentFile::update($path, ['CPANEL_DEPLOY_TOKEN' => 'replacement']))->toThrow(InvalidArgumentException::class);
    expect(File::get($path))->toBe($content);
});

it('rejects invalid source syntax without replacing or backing up the active environment', function (): void {
    $source = $this->workspace.'/.env.server';
    $target = $this->workspace.'/.env';
    File::put($source, "APP_KEY=\"unclosed-private-secret\n");
    File::put($target, "APP_KEY=valid\n");
    config()->set('cpanel-hosting.sync_env.source', $source);
    config()->set('cpanel-hosting.sync_env.target', $target);
    $result = app(SyncEnvAction::class)->execute();
    expect($result['success'])->toBeFalse()->and(json_encode($result))->not->toContain('unclosed-private-secret')
        ->and(File::get($target))->toBe("APP_KEY=valid\n")->and(File::glob($target.'.backup.*'))->toBe([]);
});

it('replaces the active environment only after validation and preserves its original backup', function (): void {
    $source = $this->workspace.'/.env.server';
    $target = $this->workspace.'/.env';
    File::put($source, "APP_KEY=new-key\nAPP_ENV=production\n");
    File::put($target, "APP_KEY=old-key\n");
    config()->set('cpanel-hosting.sync_env.source', $source);
    config()->set('cpanel-hosting.sync_env.target', $target);
    $result = app(SyncEnvAction::class)->execute();
    expect($result['success'])->toBeTrue()->and(File::get($target))->toBe(File::get($source))
        ->and(File::get($result['data']['backup']))->toBe("APP_KEY=old-key\n")
        ->and(File::glob($this->workspace.'/.cpanel-*'))->toBe([]);
});
