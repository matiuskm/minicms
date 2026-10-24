<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function edit(): Response
    {
        $items = MenuItem::orderBy('sort_order')->orderBy('id')->get(['location', 'label', 'type', 'page_id', 'category_id', 'url']);

        return Inertia::render('admin/menus', [
            'menus' => ['header' => $items->where('location', 'header')->values(), 'footer' => $items->where('location', 'footer')->values()],
            'pages' => Page::published()->orderBy('title')->get(['id', 'title']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Replaces one whole menu; the order of the array is the order shown. */
    public function update(Request $request, string $location): RedirectResponse
    {
        abort_unless(in_array($location, ['header', 'footer'], true), 404);

        $data = $request->validate([
            'items' => ['present', 'array', 'max:12'],
            'items.*.label' => ['required', 'string', 'max:60'],
            'items.*.type' => ['required', Rule::in(['page', 'category', 'url'])],
            'items.*.page_id' => ['nullable', 'required_if:items.*.type,page', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'items.*.category_id' => ['nullable', 'required_if:items.*.type,category', 'integer', 'exists:categories,id'],
            // http(s) or a site path only: javascript: and friends are rejected (TC-F10-02).
            'items.*.url' => ['nullable', 'required_if:items.*.type,url', 'string', 'max:255', 'regex:#^(https?://\S+|/(?!/)\S*)$#'],
        ]);

        DB::transaction(function () use ($data, $location) {
            MenuItem::where('location', $location)->delete();

            foreach ($data['items'] as $i => $item) {
                MenuItem::create([
                    'location' => $location,
                    'label' => $item['label'],
                    'type' => $item['type'],
                    'page_id' => $item['type'] === 'page' ? $item['page_id'] : null,
                    'category_id' => $item['type'] === 'category' ? $item['category_id'] : null,
                    'url' => $item['type'] === 'url' ? $item['url'] : null,
                    'sort_order' => $i,
                ]);
            }
        });
        AuditLog::record('menu.updated', null, ['location' => $location]);

        return back()->with('status', ucfirst($location).' menu saved.');
    }
}
