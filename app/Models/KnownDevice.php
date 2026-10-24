<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnownDevice extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /**
     * Records this browser for the user and says whether it is new to an account that already had other browsers.
     * The very first sign-in only registers the device; it never triggers a warning.
     */
    public static function seen(User $user, string $userAgent): bool
    {
        $fingerprint = hash('sha256', $userAgent);
        $hadOthers = static::where('user_id', $user->id)->exists();
        $device = static::where('user_id', $user->id)->where('fingerprint', $fingerprint)->first();

        if ($device) {
            $device->update(['last_seen_at' => now()]);

            return false;
        }

        static::create([
            'user_id' => $user->id, 'fingerprint' => $fingerprint, 'label' => mb_substr($userAgent, 0, 120),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        return $hadOthers;
    }
}
