<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting;

use Awaisjameel\LaravelCpanelHosting\Support\Settings;

final class LaravelCpanelHosting
{
    public function isEnabled(): bool
    {
        return Settings::flag('enabled', false);
    }

    public function routePrefix(): string
    {
        return Settings::text('route_prefix', 'deploy');
    }
}
