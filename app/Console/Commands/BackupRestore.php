<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Backup;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'cms:restore {db : DB dump file name on the backup disk, or a path} {--media= : optional media archive} {--force : required; this replaces the current data}';

    protected $description = 'Restore a backup into the configured database (destructive)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('This replaces every table in the current database. Re-run with --force once you are sure.');

            return self::FAILURE;
        }

        try {
            Backup::restore($this->argument('db'), $this->option('media'));
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // The restored database may predate this entry; recording it keeps the trail honest from here on.
        rescue(fn () => AuditLog::record('backup.restored', null, ['file' => basename($this->argument('db'))]), null, false);
        $this->info('Restore complete. Clear caches with: php artisan optimize:clear');

        return self::SUCCESS;
    }
}
