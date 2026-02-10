<?php

declare(strict_types=1);

it('resolves the package facade root', function (): void {
    $instance = app(\Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting::class);

    expect($instance->isEnabled())->toBeTrue();
    expect($instance->routePrefix())->toBe('deploy');
});
