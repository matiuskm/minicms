<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs `* * * * * php artisan schedule:run` in cron (see README).
Schedule::command('cms:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('cms:check-integrity')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('cms:backup')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('cms:prune-analytics')->dailyAt('03:10')->withoutOverlapping();
