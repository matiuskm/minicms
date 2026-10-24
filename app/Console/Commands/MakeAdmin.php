<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'cms:make-admin {email} {--name=Admin} {--password= : Defaults to a random password printed once}';

    protected $description = 'Create an active admin (2FA setup is forced at first login)';

    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));

        if (User::where('email', $email)->exists()) {
            $this->error("User {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(20, symbols: false);

        $user = new User(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => 'admin', 'status' => 'active'])->save();
        AuditLog::record('user.created_cli', $user);

        $this->info("Admin created: {$email}");
        if (! $this->option('password')) {
            $this->line("Password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
