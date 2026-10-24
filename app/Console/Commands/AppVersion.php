<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AppVersion extends Command
{
    protected $signature = 'cms:version';

    protected $description = 'Print the installed version (used by the updater health check)';

    public function handle(): int
    {
        $this->line((string) config('app.version'));

        return self::SUCCESS;
    }
}
