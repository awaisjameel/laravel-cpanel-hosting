<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeployStepCompleted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public readonly string $step,
        public readonly array $result,
    ) {}
}
