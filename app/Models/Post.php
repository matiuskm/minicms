<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Post extends Model
{
    use HasFactory, HasRevisions, SoftDeletes;

    public const STATUSES = ['draft', 'pending_review', 'rejected', 'scheduled', 'published', 'archived'];

    /** Allowed status moves (SA FL-02). Who may trigger them is decided in PostPolicy. */
    public const TRANSITIONS = [
        'draft' => ['pending_review', 'published', 'scheduled'],
        'rejected' => ['pending_review', 'published', 'scheduled'],
        'pending_review' => ['published', 'scheduled', 'rejected'],
        'scheduled' => ['published', 'draft'],
        'published' => ['draft', 'archived'],
        'archived' => ['draft'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['body' => SanitizedHtml::class, 'published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_media_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** Writers only see their own posts (RBAC data scope). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('author_id', $user->id);
    }

    /** What the public may read (BR-28). */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('published_at', '<=', now());
    }

    public function moveTo(string $status): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$this->status} post cannot become {$status}."]);
        }

        $this->status = $status;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->author_id === $user->id;
    }
}
