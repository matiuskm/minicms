<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/** Used by the updater to put the site in maintenance mode around a release switch. Prints the previous state. */
class MaintenanceToggle extends Command
{
    protected $signature = 'cms:maintenance {state : on or off}';

    protected $description = 'Turn maintenance mode on or off; prints "was-on" or "was-off"';

    public function handle(): int
    {
        if (! in_array($this->argument('state'), ['on', 'off'], true)) {
            $this->error('State must be "on" or "off".');

            return self::INVALID;
        }

        $was = (bool) Setting::get('maintenance_mode');
        Setting::put(['maintenance_mode' => $this->argument('state') === 'on']);
        $this->line($was ? 'was-on' : 'was-off');

        return self::SUCCESS;
    }
}
