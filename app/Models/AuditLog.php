<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only (BR-31). Model events block update/delete; DB-level enforcement
 * (INSERT/SELECT-only grants) is documented in README for production.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log is append-only.'));
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(string $action, ?Model $object = null, array $meta = [], ?int $actorId = null): self
    {
        $request = request();
        $ip = $request?->ip();

        return static::create([
            'actor_id' => $actorId ?? $request?->user()?->id,
            'action' => $action,
            'object_type' => $object ? class_basename($object) : null,
            'object_id' => $object?->getKey(),
            'ip' => $ip ? inet_pton($ip) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'meta' => $meta ?: null,
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function ipText(): ?string
    {
        return $this->ip ? inet_ntop($this->ip) : null;
    }
}
