<?php

namespace Awaisjameel\LaravelCpanelHosting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting
 */
class LaravelCpanelHosting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awaisjameel\LaravelCpanelHosting\LaravelCpanelHosting::class;
    }
}
