<?php

declare(strict_types=1);
use Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting;

it('resolves the package facade root', function (): void {
    $instance = app(LaravelCpanelHosting::class);

    expect($instance->isEnabled())->toBeTrue();
    expect($instance->routePrefix())->toBe('deploy');
});
