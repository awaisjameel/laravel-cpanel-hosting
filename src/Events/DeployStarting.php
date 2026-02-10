<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeployStarting
{
    use Dispatchable;

    /**
     * @param  array<int, mixed>  $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly ?string $ip = null,
    ) {}
}
