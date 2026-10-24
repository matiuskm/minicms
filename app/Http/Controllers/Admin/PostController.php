<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Revision;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\Slugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $posts = Post::query()->visibleTo($user)
            ->select(['id', 'author_id', 'category_id', 'title', 'slug', 'status', 'published_at', 'updated_at', 'deleted_at'])
            ->with(['author:id,name', 'category:id,name'])
            ->when($status === 'trashed', fn ($query) => $query->onlyTrashed())
            ->when(in_array($status, Post::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', (int) $request->query('category')))
            ->when($user->isAdmin() && $request->filled('author'), fn ($query) => $query->where('author_id', (int) $request->query('author')))
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->latest('updated_at')->paginate(15)->withQueryString()
            ->through(fn (Post $p) => [
                ...$p->only('id', 'title', 'slug', 'status', 'deleted_at'),
                'author' => $p->author->name,
                'category' => $p->category->name,
                'published_at' => $p->published_at?->toIso8601String(),
                'updated_at' => $p->updated_at->toIso8601String(),
            ]);

        $counts = Post::query()->visibleTo($user)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('admin/posts/index', [
            'posts' => $posts,
            'counts' => [...$counts, 'trashed' => Post::query()->visibleTo($user)->onlyTrashed()->count()],
            'filters' => ['status' => $status, 'q' => $q, 'category' => $request->query('category'), 'author' => $request->query('author')],
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'authors' => $user->isAdmin() ? User::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Post::class);

        return Inertia::render('admin/posts/form', $this->formProps(null, $request->user()));
    }

    public function edit(Request $request, int $id): Response
    {
        $post = $this->find($request, $id);
        Gate::authorize('view', $post);

        return Inertia::render('admin/posts/form', $this->formProps($post, $request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        return $this->persist($request, new Post(['author_id' => $request->user()->id, 'status' => 'draft']));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('update', $post);

        return $this->persist($request, $post);
    }

    /** Draft autosave: content only, no revision, JSON in/out (FR-37). */
    public function autosave(Request $request, int $id): JsonResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('update', $post);

        if (! in_array($post->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only drafts are autosaved.'], 422);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:2000000'],
            'version' => ['required', 'integer'],
        ]);

        if ($post->version !== (int) $data['version']) {
            return response()->json(['message' => 'This post was changed elsewhere.', 'version' => $post->version], 409);
        }

        $post->fill(['title' => $data['title'], 'excerpt' => $data['excerpt'] ?? null, 'body' => $data['body'] ?? '']);
        $post->version++;
        $post->save();

        return response()->json(['version' => $post->version, 'saved_at' => now()->toIso8601String()]);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('reject', $post);
        $data = $request->validate(['review_note' => ['required', 'string', 'max:500']]);

        $post->moveTo('rejected');
        $post->review_note = $data['review_note'];
        $post->save();
        AuditLog::record('post.rejected', $post);

        return back();
    }

    public function unpublish(Request $request, int $id): RedirectResponse
    {
        return $this->simpleMove($request, $id, 'unpublish', 'draft', 'post.unpublished');
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        return $this->simpleMove($request, $id, 'archive', 'archived', 'post.archived');
    }

    public function unarchive(Request $request, int $id): RedirectResponse
    {
        return $this->simpleMove($request, $id, 'unarchive', 'draft', 'post.unarchived');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('trash', $post);

        $post->delete();
        AuditLog::record('post.trashed', $post);

        return to_route('admin.posts.index');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('untrash', $post);

        $post->restore();
        $post->forceFill(['status' => 'draft'])->save(); // trashed -> draft (FL-02)
        AuditLog::record('post.restored', $post);

        return back();
    }

    public function purge(Request $request, int $id): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('purge', $post);

        AuditLog::record('post.purged', $post, ['title' => $post->title]);
        $post->revisions()->delete();
        $post->forceDelete();

        return to_route('admin.posts.index', ['status' => 'trashed']);
    }

    public function revisions(Request $request, int $id): JsonResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('view', $post);

        $rows = $post->revisions()->with('editor:id,name')
            ->select(['id', 'editor_id', 'title', 'created_at'])
            ->selectRaw('LEFT(body, 400) as snippet')
            ->latest('id')->get()
            ->map(fn (Revision $r) => [
                'id' => $r->id, 'title' => $r->title, 'editor' => $r->editor->name,
                'created_at' => $r->created_at->toIso8601String(),
                'snippet' => mb_substr(trim(html_entity_decode(strip_tags($r->snippet))), 0, 140),
            ]);

        return response()->json($rows);
    }

    public function restoreRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize('update', $post);
        $rev = $post->revisions()->findOrFail($revision);

        $post->fill(['title' => $rev->title, 'body' => $rev->body]);
        $post->version++;
        $post->save();
        $post->captureRevision($request->user()->id);
        AuditLog::record('post.revision_restored', $post, ['revision' => $rev->id]);

        return back()->with('status', 'Revision restored.');
    }

    private function simpleMove(Request $request, int $id, string $ability, string $to, string $audit): RedirectResponse
    {
        $post = $this->find($request, $id);
        Gate::authorize($ability, $post);

        $post->moveTo($to);
        $post->save();
        AuditLog::record($audit, $post);

        return back();
    }

    /** Writers get a 404 for posts that are not theirs (no existence leak). */
    private function find(Request $request, int $id): Post
    {
        return Post::query()->visibleTo($request->user())->withTrashed()->findOrFail($id);
    }

    private function persist(Request $request, Post $post): RedirectResponse
    {
        $user = $request->user();
        $isNew = ! $post->exists;
        $data = $this->validated($request, $post);
        $action = $data['action'];

        if (! $isNew && $post->version !== (int) $data['version']) {
            throw ValidationException::withMessages(['version' => 'This post was changed since you opened it. Reload to see the latest version.']);
        }

        $post = DB::transaction(function () use ($data, $action, $post, $user, $isNew) {
            $oldSlug = $post->slug;
            $wasPublic = in_array($post->status, ['published', 'archived'], true);

            $post->title = $data['title'];
            $post->excerpt = $data['excerpt'] ?? null;
            $post->body = $data['body'] ?? '';
            $post->meta_title = $data['meta_title'] ?? null;
            $post->meta_description = $data['meta_description'] ?? null;
            $post->category_id = $data['category_id'] ?? $post->category_id ?? Category::where('is_system', true)->value('id');
            $post->featured_media_id = $data['featured_media_id'] ?? null;
            $post->slug = filled($data['slug'] ?? null) ? $data['slug'] : ($post->slug ?: Slugger::make($data['title'], 'posts'));

            if ($action !== 'save' && HtmlSanitizer::isBlank($post->body)) {
                throw ValidationException::withMessages(['body' => 'The post needs some content.']);
            }

            $contentChanged = $isNew || $post->isDirty(['title', 'body']);
            $post->version = $isNew ? 1 : $post->version + 1;
            $this->applyAction($post, $action, $data, $user);
            $post->save();

            $post->tags()->sync($data['tag_ids'] ?? []);
            if ($contentChanged) {
                $post->captureRevision($user->id);
            }
            if ($wasPublic && $oldSlug && $oldSlug !== $post->slug) {
                Redirect::record("/blog/{$oldSlug}", "/blog/{$post->slug}"); // BR-12
            }

            AuditLog::record($isNew ? 'post.created' : 'post.updated', $post);
            if ($action !== 'save') {
                AuditLog::record(['submit' => 'post.submitted', 'publish' => 'post.published', 'schedule' => 'post.scheduled'][$action], $post);
            }

            return $post;
        });

        return to_route('admin.posts.edit', $post->id)->with('status', match ($action) {
            'submit' => 'Submitted for review.',
            'publish' => 'Published.',
            'schedule' => 'Scheduled.',
            default => 'Saved.',
        });
    }

    /** @param  array<string, mixed>  $data */
    private function applyAction(Post $post, string $action, array $data, User $user): void
    {
        if ($action === 'save') {
            return;
        }

        $ability = $action === 'submit' ? 'submit' : 'publish';
        Gate::forUser($user)->authorize($ability, $post);

        if ($action === 'submit') {
            $post->moveTo('pending_review');
            $post->review_note = null;

            return;
        }

        $at = isset($data['published_at']) ? now()->parse($data['published_at']) : null;

        if ($action === 'schedule') {
            $post->moveTo('scheduled');
            $post->published_at = $at;

            return;
        }

        if ($at?->isFuture()) {
            throw ValidationException::withMessages(['published_at' => 'Use "Schedule" for a future date.']);
        }

        $post->moveTo('published');
        $post->published_at = $at ?? ($post->published_at?->isPast() ? $post->published_at : now());
        $post->review_note = null;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Post $post): array
    {
        $user = $request->user();
        $action = $request->input('action');
        $strict = in_array($action, ['submit', 'publish', 'schedule'], true);

        return $request->validate([
            'action' => ['required', Rule::in(['save', 'submit', 'publish', 'schedule'])],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('posts', 'slug')->ignore($post->id)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => [$strict ? 'required' : 'nullable', 'string', 'max:2000000'],
            'category_id' => [$strict ? 'required' : 'nullable', 'integer', 'exists:categories,id'],
            'tag_ids' => ['nullable', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'featured_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where(fn ($q) => $q
                ->where('mime', 'like', 'image/%')
                ->when(! $user->isAdmin(), fn ($q) => $q->where('uploader_id', $user->id)))],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'published_at' => $action === 'schedule' ? ['required', 'date', 'after:now'] : ['nullable', 'date'],
            'version' => [$post->exists ? 'required' : 'nullable', 'integer'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(?Post $post, User $user): array
    {
        $post?->load(['tags:id,name', 'featuredMedia', 'author:id,name']);

        return [
            'post' => $post ? [
                ...$post->only('id', 'title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description', 'status', 'review_note', 'category_id', 'version'),
                'published_at' => $post->published_at?->toIso8601String(),
                'author' => $post->author->name,
                'tags' => $post->tags->map->only('id', 'name')->values(),
                'featured' => $post->featuredMedia?->toListArray(),
                'trashed' => $post->trashed(),
            ] : null,
            'categories' => Category::orderBy('name')->get(['id', 'parent_id', 'name', 'is_system']),
            'can' => $post ? collect(['update', 'submit', 'publish', 'reject', 'unpublish', 'archive', 'unarchive', 'trash', 'untrash', 'purge'])
                ->mapWithKeys(fn ($a) => [$a => $user->can($a, $post)])->all()
                : ['update' => true, 'submit' => true, 'publish' => $user->isAdmin()],
            'isAdmin' => $user->isAdmin(),
            'authorName' => $post?->author->name ?? $user->name,
        ];
    }
}
