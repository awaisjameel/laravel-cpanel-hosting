<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Commands;

use Illuminate\Console\Command;

final class LaravelCpanelHostingCommand extends Command
{
    protected $signature = 'laravel-cpanel-hosting';

    protected $description = 'Alias for cpanel-hosting:install.';

    public function handle(): int
    {
        return $this->call('cpanel-hosting:install');
    }
}
