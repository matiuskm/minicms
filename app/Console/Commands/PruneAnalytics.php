<?php

namespace App\Console\Commands;

use App\Support\Analytics\Tracker;
use Illuminate\Console\Command;

class PruneAnalytics extends Command
{
    protected $signature = 'cms:prune-analytics';

    protected $description = 'Delete visit records older than the configured retention (default 90 days)';

    public function handle(): int
    {
        $this->info('Deleted '.Tracker::prune().' old visit record(s).');

        return self::SUCCESS;
    }
}
