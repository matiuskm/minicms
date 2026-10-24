<?php

namespace App\Console\Commands;

use App\Support\Backup;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'cms:backup';

    protected $description = 'Dump the database and archive uploads to the backup disk (keeps the latest sets)';

    public function handle(): int
    {
        try {
            $r = Backup::run();
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup written: {$r['db']}".($r['media'] ? " + {$r['media']}" : '').' ('.number_format($r['bytes'] / 1024, 1).' KB)');

        return self::SUCCESS;
    }
}
