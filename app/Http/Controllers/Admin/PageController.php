<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Revision;
use App\Support\HtmlSanitizer;
use App\Support\Slugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Pages are admin-only (BR-17); the route group enforces the role. */
class PageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $pages = Page::query()
            ->select(['id', 'parent_id', 'title', 'slug', 'status', 'sort_order', 'updated_at', 'deleted_at'])
            ->with('parent:id,title')
            ->when($status === 'trashed', fn ($query) => $query->onlyTrashed())
            ->when(in_array($status, ['draft', 'published'], true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->orderBy('sort_order')->orderBy('title')
            ->paginate(20)->withQueryString()
            ->through(fn (Page $p) => [
                ...$p->only('id', 'title', 'slug', 'status', 'sort_order', 'deleted_at'),
                'parent' => $p->parent?->title,
                'updated_at' => $p->updated_at->toIso8601String(),
            ]);

        return Inertia::render('admin/pages/index', [
            'pages' => $pages,
            'counts' => [...Page::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'), 'trashed' => Page::onlyTrashed()->count()],
            'filters' => ['status' => $status, 'q' => $q],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/pages/form', $this->formProps(null));
    }

    public function edit(int $id): Response
    {
        return Inertia::render('admin/pages/form', $this->formProps(Page::withTrashed()->findOrFail($id)));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request, new Page(['status' => 'draft']));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $page = Page::findOrFail($id);

        return $this->persist($request, $page);
    }

    public function unpublish(int $id): RedirectResponse
    {
        $page = Page::findOrFail($id);
        $page->status = 'draft';
        $page->save();
        AuditLog::record('page.unpublished', $page);

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        $page = Page::findOrFail($id);

        if ($page->children()->exists()) {
            throw ValidationException::withMessages(['page' => 'Move or delete the child pages first.']);
        }

        $page->delete();
        AuditLog::record('page.trashed', $page);

        return to_route('admin.pages.index');
    }

    public function restore(int $id): RedirectResponse
    {
        $page = Page::onlyTrashed()->findOrFail($id);
        $page->restore();
        $page->forceFill(['status' => 'draft'])->save();
        AuditLog::record('page.restored', $page);

        return back();
    }

    public function purge(int $id): RedirectResponse
    {
        $page = Page::onlyTrashed()->findOrFail($id);

        AuditLog::record('page.purged', $page, ['title' => $page->title]);
        $page->revisions()->delete();
        $page->forceDelete();

        return to_route('admin.pages.index', ['status' => 'trashed']);
    }

    public function revisions(int $id): JsonResponse
    {
        $page = Page::withTrashed()->findOrFail($id);

        return response()->json($page->revisions()->with('editor:id,name')
            ->select(['id', 'editor_id', 'title', 'created_at'])
            ->selectRaw('LEFT(body, 400) as snippet')
            ->latest('id')->get()
            ->map(fn (Revision $r) => [
                'id' => $r->id, 'title' => $r->title, 'editor' => $r->editor->name,
                'created_at' => $r->created_at->toIso8601String(),
                'snippet' => mb_substr(trim(html_entity_decode(strip_tags($r->snippet))), 0, 140),
            ]));
    }

    public function restoreRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $page = Page::findOrFail($id);
        $rev = $page->revisions()->findOrFail($revision);

        $page->fill(['title' => $rev->title, 'body' => $rev->body]);
        $page->version++;
        $page->updated_by = $request->user()->id;
        $page->save();
        $page->captureRevision($request->user()->id);
        AuditLog::record('page.revision_restored', $page, ['revision' => $rev->id]);

        return back()->with('status', 'Revision restored.');
    }

    private function persist(Request $request, Page $page): RedirectResponse
    {
        $user = $request->user();
        $isNew = ! $page->exists;
        $data = $this->validated($request, $page);

        if (! $isNew && $page->version !== (int) $data['version']) {
            throw ValidationException::withMessages(['version' => 'This page was changed since you opened it. Reload to see the latest version.']);
        }

        $page = DB::transaction(function () use ($data, $page, $user, $isNew) {
            $oldSlug = $page->slug;
            $wasPublished = $page->status === 'published';

            $page->title = $data['title'];
            $page->body = $data['body'] ?? '';
            $page->meta_title = $data['meta_title'] ?? null;
            $page->meta_description = $data['meta_description'] ?? null;
            $page->parent_id = $data['parent_id'] ?? null;
            $page->sort_order = $data['sort_order'] ?? 0;
            $page->slug = filled($data['slug'] ?? null) ? $data['slug'] : ($page->slug ?: Slugger::make($data['title'], 'pages'));
            $page->updated_by = $user->id;

            $publishing = $data['action'] === 'publish';
            if ($publishing && HtmlSanitizer::isBlank($page->body)) {
                throw ValidationException::withMessages(['body' => 'The page needs some content.']);
            }
            if ($publishing) {
                $page->status = 'published';
            }

            $contentChanged = $isNew || $page->isDirty(['title', 'body']);
            $page->version = $isNew ? 1 : $page->version + 1;
            $page->save();

            if ($contentChanged) {
                $page->captureRevision($user->id);
            }
            if ($wasPublished && $oldSlug && $oldSlug !== $page->slug) {
                Redirect::record("/{$oldSlug}", "/{$page->slug}");
            }

            AuditLog::record($isNew ? 'page.created' : 'page.updated', $page);
            if ($publishing && ! $wasPublished) {
                AuditLog::record('page.published', $page);
            }

            return $page;
        });

        return to_route('admin.pages.edit', $page->id)->with('status', $data['action'] === 'publish' ? 'Published.' : 'Saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Page $page): array
    {
        $publishing = $request->input('action') === 'publish';

        $data = $request->validate([
            'action' => ['required', Rule::in(['save', 'publish'])],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('cms.reserved_slugs')), Rule::unique('pages', 'slug')->ignore($page->id)],
            'body' => [$publishing ? 'required' : 'nullable', 'string', 'max:2000000'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'parent_id' => ['nullable', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'sort_order' => ['nullable', 'integer', 'between:0,65000'],
            'version' => [$page->exists ? 'required' : 'nullable', 'integer'],
        ]);

        // One level deep (A-05): the parent must be top level, not this page, and this page must not have children.
        if ($data['parent_id'] ?? null) {
            $parent = Page::findOrFail($data['parent_id']);
            if ($parent->parent_id !== null || $parent->id === $page->id || ($page->exists && $page->children()->exists())) {
                throw ValidationException::withMessages(['parent_id' => 'Pages can only be nested one level deep.']);
            }
        }

        // The generated slug (no explicit slug given) must not collide with application routes either.
        if (blank($data['slug'] ?? null) && ! $page->slug) {
            $generated = Slugger::make($data['title'], 'pages');
            if (in_array($generated, config('cms.reserved_slugs'), true)) {
                throw ValidationException::withMessages(['slug' => 'That title maps to a reserved address; enter a different slug.']);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formProps(?Page $page): array
    {
        return [
            'page' => $page ? [
                ...$page->only('id', 'title', 'slug', 'body', 'meta_title', 'meta_description', 'status', 'parent_id', 'sort_order', 'version'),
                'trashed' => $page->trashed(),
            ] : null,
            'parents' => Page::whereNull('parent_id')->when($page, fn ($q) => $q->where('id', '!=', $page->id))
                ->orderBy('title')->get(['id', 'title']),
        ];
    }
}
