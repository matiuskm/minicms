<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    // role/status are never mass-assignable; set them explicitly.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Revoke database sessions, optionally keeping the current one. */
    public function endSessions(?string $except = null): void
    {
        DB::table('sessions')->where('user_id', $this->id)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->delete();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** BR-07: true when this user is the only active admin left. */
    public function isLastActiveAdmin(): bool
    {
        return $this->isAdmin() && $this->isActive()
            && static::where('role', 'admin')->where('status', 'active')->count() <= 1;
    }
}
