<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Admin: everything. Writer: only their own posts, and only while those are draft or rejected (BR-08, BR-09).
 * Moves out of the "editable by writer" states are never available to writers except submit.
 */
class PostPolicy
{
    private function editableByWriter(Post $post): bool
    {
        return in_array($post->status, ['draft', 'rejected'], true);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $user->isAdmin() || $post->isOwnedBy($user);
    }

    public function update(User $user, Post $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        return $user->isAdmin() || ($post->isOwnedBy($user) && $this->editableByWriter($post));
    }

    public function submit(User $user, Post $post): bool
    {
        return ! $post->trashed() && $post->isOwnedBy($user) && $this->editableByWriter($post);
    }

    /** Publish or schedule. Admins may fast-track their own drafts; others must come through review. */
    public function publish(User $user, Post $post): bool
    {
        if (! $user->isAdmin() || $post->trashed()) {
            return false;
        }

        return in_array($post->status, ['pending_review', 'scheduled'], true)
            || ($this->editableByWriter($post) && $post->isOwnedBy($user));
    }

    public function reject(User $user, Post $post): bool
    {
        return $user->isAdmin() && ! $post->trashed() && $post->status === 'pending_review';
    }

    public function unpublish(User $user, Post $post): bool
    {
        return $user->isAdmin() && ! $post->trashed() && in_array($post->status, ['published', 'scheduled'], true);
    }

    public function archive(User $user, Post $post): bool
    {
        return $user->isAdmin() && ! $post->trashed() && $post->status === 'published';
    }

    public function unarchive(User $user, Post $post): bool
    {
        return $user->isAdmin() && ! $post->trashed() && $post->status === 'archived';
    }

    public function trash(User $user, Post $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        return $user->isAdmin() || ($post->isOwnedBy($user) && $this->editableByWriter($post));
    }

    public function untrash(User $user, Post $post): bool
    {
        return $post->trashed() && ($user->isAdmin() || $post->isOwnedBy($user));
    }

    public function purge(User $user, Post $post): bool
    {
        return $user->isAdmin() && $post->trashed();
    }
}
