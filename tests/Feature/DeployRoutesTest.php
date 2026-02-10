<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('returns 403 when token is missing', function (): void {
    $this->getJson('/deploy/health')
        ->assertForbidden();
});

it('accepts token via query string', function (): void {
    $this->getJson('/deploy/health?token=test-deploy-token')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('accepts token via header', function (): void {
    $this->withHeaders(['X-Deploy-Token' => 'test-deploy-token'])
        ->getJson('/deploy/health')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('returns 404 when package is disabled', function (): void {
    config()->set('cpanel-hosting.enabled', false);

    $this->getJson('/deploy/health?token=test-deploy-token')
        ->assertNotFound();
});

it('accepts valid gitlab webhook token', function (): void {
    config()->set('cpanel-hosting.webhook_secret', 'webhook-secret');

    $this->withHeaders(['X-Gitlab-Token' => 'webhook-secret'])
        ->getJson('/deploy/health')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('runs deploy pipeline endpoint with configured steps', function (): void {
    $workspace = base_path('tests-temp/deploy');
    $source = "{$workspace}/.env.server";
    $target = "{$workspace}/.env";

    File::deleteDirectory($workspace);
    File::ensureDirectoryExists($workspace);
    File::put($source, "APP_KEY=base64:123456\nAPP_ENV=testing\n");

    config()->set('cpanel-hosting.sync_env.source', $source);
    config()->set('cpanel-hosting.sync_env.target', $target);
    config()->set('cpanel-hosting.sync_env.required_keys', ['APP_KEY']);
    config()->set('cpanel-hosting.pipeline.default_steps', ['sync-env']);

    $this->getJson('/deploy?token=test-deploy-token')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.steps.0.step', 'sync-env');

    File::deleteDirectory(base_path('tests-temp'));
});
