<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Retention for the audit trail (NFR-13). The running site's database account can only INSERT/SELECT on audit_logs
 * (database/hardening/production-grants.sql), so this is NOT part of the web scheduler: run it from cron as the
 * deploy user with the migrator credentials (docs/DEPLOY.md).
 */
class PruneAudit extends Command
{
    protected $signature = 'cms:prune-audit {--months= : override the retention in months}';

    protected $description = 'Delete audit log entries older than the retention period (default 12 months)';

    public function handle(): int
    {
        $months = max(1, (int) ($this->option('months') ?: config('cms.audit_retention_months')));
        $cutoff = now()->subMonths($months);
        $deleted = 0;

        try {
            do {
                $n = DB::table('audit_logs')->where('created_at', '<', $cutoff)->limit(5000)->delete();
                $deleted += $n;
            } while ($n === 5000);
        } catch (QueryException $e) {
            $this->error('Could not delete: this database account is not allowed to (run it with the migrator credentials). '.mb_substr($e->getMessage(), 0, 120));

            return self::FAILURE;
        }

        $this->info("Deleted {$deleted} audit entr".($deleted === 1 ? 'y' : 'ies')." older than {$months} months.");

        return self::SUCCESS;
    }
}
