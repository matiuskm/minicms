<?php

namespace App\Models;

use App\Support\PublicCache;
use App\Support\Slugger;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // role/status are never mass-assignable; set them explicitly.
    protected $fillable = ['name', 'display_name', 'email', 'password'];

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

    protected static function booted(): void
    {
        // The public byline (and its archive address) follows the display name. Old addresses keep working.
        static::saving(function (self $user) {
            if ($user->author_slug && ! $user->isDirty(['name', 'display_name'])) {
                return;
            }

            $old = $user->author_slug;
            $user->author_slug = null;
            $user->author_slug = Slugger::make($user->publicName(), 'users', $user->id, 'author_slug');
            if ($old && $old !== $user->author_slug) {
                Redirect::record("/author/{$old}", "/author/{$user->author_slug}");
            }
        });

        static::saved(function (self $user) {
            if ($user->wasChanged(['name', 'display_name', 'author_slug'])) {
                PublicCache::bust();
            }
        });
    }

    /** Revoke database sessions, optionally keeping the current one. */
    public function endSessions(?string $except = null): void
    {
        DB::table('sessions')->where('user_id', $this->id)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->delete();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    /** The only name visitors ever see (bylines, feeds, structured data). */
    public function publicName(): string
    {
        return $this->display_name ?: $this->name;
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
