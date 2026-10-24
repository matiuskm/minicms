<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\UpdateAvailable;
use App\Support\Updates\Updater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckUpdates extends Command
{
    protected $signature = 'cms:check-updates';

    protected $description = 'Look for a newer release and tell the admins once per version';

    public function handle(): int
    {
        $available = Updater::available(refresh: true);

        if (! $available) {
            $this->info('Up to date ('.Updater::current().').');

            return self::SUCCESS;
        }

        $this->info("Version {$available['version']} is available (installed: ".Updater::current().').');

        if (Setting::get('update_notified') !== $available['version']) {
            Notification::send(User::where('role', 'admin')->where('status', 'active')->get(), new UpdateAvailable($available['version'], Updater::current()));
            Setting::put(['update_notified' => $available['version']]);
        }

        return self::SUCCESS;
    }
}
