<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $guarded = ['id'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    /** Writers only see their own uploads (RBAC data scope). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('uploader_id', $user->id);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** Images are public (re-encoded); PDFs live on the private disk and are streamed with safe headers. */
    public function disk(): string
    {
        return $this->isImage() ? 'public' : 'local';
    }

    public static function variantPath(string $path, string $variant): string
    {
        return preg_replace('/(\.[a-z0-9]+)$/', "-{$variant}$1", $path);
    }

    /** @return list<string> */
    public function allPaths(): array
    {
        if (! $this->isImage()) {
            return [$this->path];
        }

        return [$this->path, ...array_map(fn ($v) => self::variantPath($this->path, $v), array_keys(config('cms.uploads.variants')))];
    }

    public function deleteFiles(): void
    {
        Storage::disk($this->disk())->delete($this->allPaths());
    }

    public function url(?string $variant = null): string
    {
        if (! $this->isImage()) {
            return route('files.show', $this->uuid);
        }

        return Storage::disk('public')->url($variant ? self::variantPath($this->path, $variant) : $this->path);
    }

    /** @return array<string, mixed> */
    public function toListArray(bool $withUploader = false): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->isImage() ? 'image' : 'pdf',
            'name' => $this->original_name,
            'alt_text' => $this->alt_text,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url(),
            'thumb_url' => $this->isImage() ? $this->url('thumb') : null,
            'uploader' => $withUploader ? $this->uploader?->name : null,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
