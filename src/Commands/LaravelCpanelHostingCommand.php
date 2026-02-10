<?php

namespace Awaisjameel\LaravelCpanelHosting\Commands;

use Illuminate\Console\Command;

class LaravelCpanelHostingCommand extends Command
{
    public $signature = 'laravel-cpanel-hosting';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
