<?php

namespace App\Models\Concerns;

use App\Models\Revision;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Revision history (FR-36) and the integrity hash (NFR-03) shared by posts and pages. */
trait HasRevisions
{
    public const MAX_REVISIONS = 50;

    public static function bootHasRevisions(): void
    {
        // Hash of what is stored, so P-06 can detect edits made outside the application.
        static::saving(function ($model) {
            $model->content_hash = hash('sha256', $model->title."\n".$model->body);
        });
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisionable');
    }

    public function captureRevision(int $editorId): void
    {
        $this->revisions()->create(['editor_id' => $editorId, 'title' => $this->title, 'body' => $this->body]);

        $stale = $this->revisions()->orderByDesc('id')->skip(self::MAX_REVISIONS)->take(PHP_INT_MAX)->pluck('id');
        if ($stale->isNotEmpty()) {
            Revision::whereIn('id', $stale)->delete();
        }
    }
}
