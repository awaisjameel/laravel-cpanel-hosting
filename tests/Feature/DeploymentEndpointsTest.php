<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

it('executes each granular artisan route through the shared action', function (string $endpoint, string $command, array $parameters): void {
    Artisan::swap(Mockery::mock(Kernel::class));
    Artisan::shouldReceive('call')->once()->with($command, $parameters)->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('completed');
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->postJson('/deploy/'.$endpoint)
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.result.command', $command);
})->with([
    ['clear', 'optimize:clear', []],
    ['migrate', 'migrate', ['--force' => true]],
    ['migrate-fresh', 'migrate:fresh', ['--force' => true]],
    ['queue-restart', 'queue:restart', []],
    ['maintenance-down', 'down', ['--retry' => 60]],
    ['maintenance-up', 'up', []],
    ['optimize', 'optimize', []],
]);

it('returns consistent structured errors when a granular command fails', function (): void {
    Artisan::swap(Mockery::mock(Kernel::class));
    Artisan::shouldReceive('call')->once()->with('optimize', [])->andReturn(1);
    Artisan::shouldReceive('output')->once()->andReturn('failure');
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->postJson('/deploy/optimize')
        ->assertStatus(500)->assertJsonPath('success', false)->assertJsonCount(1, 'errors');
});

it('returns pipeline failures without pretending successful completion', function (): void {
    config()->set('cpanel-hosting.pipeline.default_steps', [fn () => false]);
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->postJson('/deploy')
        ->assertStatus(500)->assertJsonPath('success', false)->assertJsonPath('data.steps.0.result.success', false);
});
