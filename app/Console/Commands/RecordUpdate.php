<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Support\Updates\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Called by the updater when it finishes, so the audit trail and the admins know what happened. */
class RecordUpdate extends Command
{
    protected $signature = 'cms:record-update {from} {to} {result : success|failed|rolled_back} {--note=}';

    protected $description = 'Record the outcome of an update in the audit log and email the admins';

    public function handle(): int
    {
        [$from, $to, $result] = [$this->argument('from'), $this->argument('to'), $this->argument('result')];

        if (! Version::valid($from) || ! Version::valid($to) || ! in_array($result, ['success', 'failed', 'rolled_back'], true)) {
            $this->error('Invalid arguments.');

            return self::INVALID;
        }

        $note = mb_substr((string) $this->option('note'), 0, 300);
        AuditLog::record("update.{$result}", null, ['from' => $from, 'to' => $to, 'note' => $note]);

        Notification::send(User::where('role', 'admin')->where('status', 'active')->get(), new SecurityNotice(
            match ($result) {
                'success' => "Updated to version {$to}",
                'rolled_back' => "Update to {$to} failed and was rolled back",
                default => "Update to {$to} failed",
            },
            array_filter(["Version {$from} → {$to}.", $note ?: null]),
        ));

        return self::SUCCESS;
    }
}
