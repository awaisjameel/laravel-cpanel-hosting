<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Actions\DeployAction;
use Awaisjameel\LaravelCpanelHosting\Events\DeployCompleted;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStarting;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStepCompleted;
use Awaisjameel\LaravelCpanelHosting\Tests\Fixtures\SuccessfulStep;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Artisan::swap(Mockery::mock(Kernel::class));
});

it('stops on callback failure and completes the lifecycle', function (): void {
    Event::fake([DeployStarting::class, DeployCompleted::class, DeployStepCompleted::class]);
    config()->set('cpanel-hosting.pipeline.default_steps', [fn () => false, fn () => throw new LogicException('must not run')]);
    $result = app(DeployAction::class)->execute('127.0.0.1');
    expect($result['success'])->toBeFalse()->and($result['data']['steps'])->toHaveCount(1);
    Event::assertDispatched(DeployStarting::class, fn ($event) => $event->ip === '127.0.0.1');
    Event::assertDispatched(DeployCompleted::class, fn ($event) => ! $event->success && count($event->steps) === 1);
    Event::assertDispatchedTimes(DeployStepCompleted::class, 1);
});

it('continues after thrown and malformed callback results when configured', function (): void {
    config()->set('cpanel-hosting.pipeline.stop_on_failure', false);
    config()->set('cpanel-hosting.pipeline.default_steps', [
        fn () => throw new RuntimeException('test callback failure'),
        fn () => ['success' => 'false'],
        fn () => ['success' => true, 'data' => ['accepted' => true]],
    ]);
    $result = app(DeployAction::class)->execute();
    expect($result['success'])->toBeFalse()->and($result['data']['steps'])->toHaveCount(3)
        ->and($result['data']['steps'][2]['result']['data']['accepted'])->toBeTrue();
});

it('rejects empty pipelines without running default migrations', function (): void {
    Artisan::shouldReceive('call')->never();
    config()->set('cpanel-hosting.pipeline.default_steps', []);
    expect(app(DeployAction::class)->execute()['success'])->toBeFalse();
});

it('resolves custom deployment step classes through the container', function (): void {
    config()->set('cpanel-hosting.pipeline.default_steps', [SuccessfulStep::class]);
    expect(app(DeployAction::class)->execute()['success'])->toBeTrue();
});

it('rejects invalid steps and malformed callable results', function (mixed $step): void {
    expect(app(DeployAction::class)->executeStep($step)['success'])->toBeFalse();
})->with([
    'number' => [123],
    'unknown' => ['unknown'],
    'parameters' => [['command' => 'example', 'parameters' => false]],
    'null callback' => [fn () => null],
    'errors callback' => [fn () => ['success' => false, 'errors' => [new stdClass]]],
    'message callback' => [fn () => ['success' => true, 'message' => []]],
]);

it('executes named artisan steps with the correct parameters', function (string $step, string $command, array $parameters): void {
    Artisan::shouldReceive('call')->once()->with($command, $parameters)->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('done');
    expect(app(DeployAction::class)->executeStep($step)['success'])->toBeTrue();
})->with([
    ['clear', 'optimize:clear', []],
    ['migrate', 'migrate', ['--force' => true]],
    ['migrate-fresh', 'migrate:fresh', ['--force' => true]],
    ['queue-restart', 'queue:restart', []],
    ['maintenance-up', 'up', []],
    ['optimize', 'optimize', []],
    ['artisan:custom:command', 'custom:command', []],
]);

it('redacts maintenance secrets from parameters and command output', function (): void {
    config()->set('cpanel-hosting.maintenance.secret', 'private-test-secret');
    Artisan::shouldReceive('call')->with('down', ['--retry' => 60, '--secret' => 'private-test-secret'])->once()->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('Bypass: /private-test-secret');
    $result = app(DeployAction::class)->executeStep('maintenance-down');
    expect(json_encode($result))->not->toContain('private-test-secret')
        ->and($result['data']['parameters']['--secret'])->toBe('[REDACTED]');
});

it('reports failed cache commands without losing subsequent command results', function (): void {
    foreach (['config:cache' => 0, 'route:cache' => 1, 'view:cache' => 0, 'event:cache' => 0] as $command => $exit) {
        Artisan::shouldReceive('call')->with($command, [])->once()->andReturn($exit);
    }
    Artisan::shouldReceive('output')->times(4)->andReturn('command output');
    $result = app(DeployAction::class)->executeStep('cache');
    expect($result['success'])->toBeFalse()->and($result['data']['commands'])->toHaveCount(4)->and($result['errors'])->toHaveCount(1);
});

it('runs configured command arrays and exposes command failures', function (): void {
    Artisan::shouldReceive('call')->with('custom:command', ['argument' => 'value'])->once()->andReturn(2);
    Artisan::shouldReceive('output')->once()->andReturn('failure');
    $result = app(DeployAction::class)->executeStep(['command' => 'custom:command', 'parameters' => ['argument' => 'value']]);
    expect($result['success'])->toBeFalse()->and($result['data']['exit_code'])->toBe(2);
});
