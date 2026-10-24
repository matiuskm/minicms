<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Pre-deploy checklist for NFR-03/05/09. Exits non-zero on any failure so it can gate a deploy script. */
class CheckProduction extends Command
{
    protected $signature = 'cms:check-production';

    protected $description = 'Verify the environment is configured for production';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        $checks = [
            'APP_ENV is production' => app()->isProduction(),
            'APP_DEBUG is off' => ! config('app.debug'),
            'APP_KEY is set' => filled(config('app.key')),
            'APP_URL uses https' => str_starts_with((string) config('app.url'), 'https://'),
            'Session cookie is Secure' => config('session.secure') === true,
            'Session payloads are encrypted' => config('session.encrypt') === true,
            'Passwords hashed with Argon2id' => config('hashing.driver') === 'argon2id',
            'Admin 2FA is required' => (bool) config('cms.require_admin_2fa'),
            'Database user is not root' => ! in_array($db['username'] ?? 'root', ['root', ''], true),
            'Mail is not the log/array driver' => ! in_array(config('mail.default'), ['log', 'array'], true),
            'Vite dev server is not running (public/hot absent)' => ! file_exists(public_path('hot')),
            'Storage is writable' => is_writable(storage_path()) && is_writable(storage_path('app')),
            'Application code is not writable by this user' => ! is_writable(app_path()),
            'No .env inside the web root' => ! file_exists(public_path('.env')),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>')."  {$label}");
            $failed += $ok ? 0 : 1;
        }

        $this->newLine();
        $failed ? $this->error("{$failed} check(s) failed.") : $this->info('All checks passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
