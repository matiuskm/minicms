<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tag;
use App\Support\Slugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('admin/tags/index', [
            'tags' => Tag::query()
                ->withCount(['posts as posts_count' => fn ($p) => $p->published()])
                ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => ['q' => $q],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:tags,name']]);

        $tag = Tag::create(['name' => trim($data['name']), 'slug' => Slugger::make($data['name'], 'tags')]);
        AuditLog::record('tag.created', $tag);

        return back();
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tags', 'name')->ignore($tag->id)],
        ]);

        $tag->update(['name' => trim($data['name'])]);
        AuditLog::record('tag.updated', $tag);

        return back();
    }

    /** Move every post from $source to $target, then delete $source (FR-22). */
    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:tags,id'],
            'target_id' => ['required', 'integer', 'exists:tags,id', 'different:source_id'],
        ]);
        $source = Tag::findOrFail($data['source_id']);
        $target = Tag::findOrFail($data['target_id']);

        DB::transaction(function () use ($source, $target) {
            $target->posts()->syncWithoutDetaching($source->posts()->pluck('posts.id')); // no duplicate pivot rows
            AuditLog::record('tag.merged', $target, ['merged' => $source->name]);
            $source->delete();
        });

        return back();
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        AuditLog::record('tag.deleted', $tag, ['name' => $tag->name]);
        $tag->delete();

        return back();
    }

    /** Suggestions for the editor's tag input. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json(Tag::query()->when($q !== '', fn ($query) => $query->where('name', 'like', "{$q}%"))
            ->orderBy('name')->limit(10)->get(['id', 'name']));
    }

    /** Writers (and admins) create tags from the editor; an existing name is returned, never duplicated. */
    public function findOrCreate(Request $request): JsonResponse
    {
        $name = trim($request->validate(['name' => ['required', 'string', 'max:60']])['name']);

        $tag = Tag::where('name', $name)->first(); // case-insensitive collation (BR-19)

        if (! $tag) {
            $tag = Tag::create(['name' => $name, 'slug' => Slugger::make($name, 'tags')]);
            AuditLog::record('tag.created', $tag);
        }

        return response()->json($tag->only('id', 'name', 'slug'), $tag->wasRecentlyCreated ? 201 : 200);
    }
}
