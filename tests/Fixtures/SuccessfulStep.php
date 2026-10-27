<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Tests\Fixtures;

use Awaisjameel\LaravelCpanelHosting\Contracts\DeploymentStep;

final class SuccessfulStep implements DeploymentStep
{
    public function execute(): bool
    {
        return true;
    }
}
