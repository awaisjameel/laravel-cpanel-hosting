<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Contracts;

use Awaisjameel\LaravelCpanelHosting\Support\StepResult;

/** @phpstan-import-type Result from StepResult */
interface DeploymentStep
{
    /** @return Result|bool */
    public function execute(): array|bool;
}
