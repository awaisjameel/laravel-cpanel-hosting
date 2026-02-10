<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting
 */
final class LaravelCpanelHosting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting::class;
    }
}
