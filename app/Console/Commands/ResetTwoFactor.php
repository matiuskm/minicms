<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Recovery for a lost authenticator: needs shell access to the server, so it is not an attack surface. */
class ResetTwoFactor extends Command
{
    protected $signature = 'cms:reset-2fa {email}';

    protected $description = 'Remove two-factor authentication from an account and end its sessions';

    public function handle(): int
    {
        $user = User::where('email', Str::lower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $user->endSessions();
        AuditLog::record('2fa.reset_cli', $user);

        $this->info("Two-factor reset for {$user->email}. ".($user->isAdmin() ? 'They must set it up again at next login.' : ''));

        return self::SUCCESS;
    }
}
