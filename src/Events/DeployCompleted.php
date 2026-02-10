<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeployCompleted
{
    use Dispatchable;

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $steps,
    ) {}
}
