<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Support\Slugger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $all = Category::query()->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug', 'description', 'is_system']);

        // One query; the parent name is resolved in memory (no N+1).
        $names = $all->pluck('name', 'id');

        return Inertia::render('admin/categories/index', [
            'categories' => $all->map(fn (Category $c) => [
                ...$c->only('id', 'parent_id', 'name', 'slug', 'description', 'is_system'),
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

        // ponytail: posts are re-assigned to "Uncategorized" here once Posts exist (P-03, BR-18).
        AuditLog::record('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category?->id)],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:255'],
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
