<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Support\Slugger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        // FR-23: published-post counter as a subquery, still one query for the whole list.
        $all = Category::query()->orderBy('name')
            ->withCount(['posts as posts_count' => fn ($q) => $q->published()])
            ->get(['id', 'parent_id', 'name', 'slug', 'description', 'is_system', 'hide_from_blog', 'list_layout', 'url_prefix']);

        // One query; the parent name is resolved in memory (no N+1).
        $names = $all->pluck('name', 'id');

        return Inertia::render('admin/categories/index', [
            'categories' => $all->map(fn (Category $c) => [
                ...$c->only('id', 'parent_id', 'name', 'slug', 'description', 'is_system', 'hide_from_blog', 'list_layout', 'url_prefix', 'posts_count'),
                'parent' => $c->parent_id ? $names[$c->parent_id] : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $category = Category::create([
            ...$data,
            'slug' => $data['slug'] ?? Slugger::make($data['name'], 'categories'),
        ]);
        AuditLog::record('category.created', $category);

        return back();
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        abort_if($category->is_system, 422, 'The system category cannot be edited.');

        $data = $this->validated($request, $category);
        $category->update([...$data, 'slug' => $data['slug'] ?? $category->slug]);
        AuditLog::record('category.updated', $category);

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        abort_if($category->is_system, 422, 'The system category cannot be deleted.');

        if ($category->children()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move or delete its sub-categories first.']);
        }

        DB::transaction(function () use ($category) {
            // BR-18: posts (including trashed ones) fall back to "Uncategorized".
            Post::withTrashed()->where('category_id', $category->id)
                ->update(['category_id' => Category::where('is_system', true)->value('id')]);
            AuditLog::record('category.deleted', $category, ['name' => $category->name]);
            $category->delete();
        });

        return back();
    }

    /** A url_prefix may not shadow a route the site already has (blog, admin, login, ...) or a page. */
    private function unusedPath(string $attribute, mixed $value, \Closure $fail): void
    {
        $taken = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => strtok($r->uri(), '/'))
            ->filter(fn ($first) => $first && ! str_starts_with($first, '{'))->push('build', 'storage');

        if ($taken->contains($value) || Page::where('slug', $value)->exists()) {
            $fail('That path is already used by the site.');
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category?->id)],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'hide_from_blog' => ['boolean'],
            'list_layout' => ['boolean'],
            'url_prefix' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'url_prefix')->ignore($category?->id), $this->unusedPath(...)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        // Hierarchy is one level deep (A-05): a parent must itself be top level.
        if ($data['parent_id'] ?? null) {
            $parent = Category::findOrFail($data['parent_id']);
            $invalid = $parent->parent_id !== null
                || $parent->id === $category?->id
                || ($category && $category->children()->exists());

            if ($invalid) {
                throw ValidationException::withMessages(['parent_id' => 'Categories can only be nested one level deep.']);
            }
        }

        return $data;
    }
}
