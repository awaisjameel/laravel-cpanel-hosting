<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting;

final class LaravelCpanelHosting
{
    public function isEnabled(): bool
    {
        return (bool) config('cpanel-hosting.enabled', false);
    }

    public function routePrefix(): string
    {
        return (string) config('cpanel-hosting.route_prefix', 'deploy');
    }
}
