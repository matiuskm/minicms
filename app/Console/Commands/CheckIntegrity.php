<?php

namespace App\Console\Commands;

use App\Support\IntegrityChecker;
use Illuminate\Console\Command;

class CheckIntegrity extends Command
{
    protected $signature = 'cms:check-integrity';

    protected $description = 'Detect published content edited outside the application (alerts admins on change)';

    public function handle(): int
    {
        $r = IntegrityChecker::run();
        $this->info("Checked {$r['checked']} item(s); ".count($r['mismatches']).' mismatch(es).');

        foreach ($r['mismatches'] as $m) {
            $this->warn("  {$m['type']} #{$m['id']}: {$m['title']}");
        }

        return $r['mismatches'] ? self::FAILURE : self::SUCCESS;
    }
}
