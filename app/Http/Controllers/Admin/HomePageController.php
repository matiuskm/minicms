<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Support\HomePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Layout of the public home page (F-16): which sections, in what order. Admin only (route group). */
class HomePageController extends Controller
{
    public function edit(): Response
    {
        $sections = HomePage::layout();
        $media = Media::whereIn('id', HomePage::mediaIds($sections))->get()->keyBy('id');
        $withThumb = fn (array $s) => $s + ['thumb' => ($m = $media[$s['media_id'] ?? 0] ?? null) ? $m->url('thumb') : null];

        return Inertia::render('admin/home-page', [
            'sections' => collect($sections)->map(fn ($s) => match ($s['type']) {
                'image' => $withThumb($s),
                'slider' => [...$s, 'slides' => array_map($withThumb, $s['slides'])],
                default => $s,
            })->all(),
            'posts' => Post::published()->orderByDesc('published_at')->limit(100)->get(['id', 'title']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = (array) $request->input('sections', []);
        $data = $request->validate(HomePage::rules($input));

        // Rebuilt from the validated keys only, then checked as a whole.
        $sections = HomePage::normalize($data['sections']);
        if ($problems = HomePage::problems($sections)) {
            throw ValidationException::withMessages($problems);
        }

        Setting::put(['home_sections' => $sections]);
        AuditLog::record('home.updated', null, ['sections' => array_column($sections, 'type')]);

        return back()->with('status', 'Home page saved.');
    }

    public function reset(): RedirectResponse
    {
        Setting::put(['home_sections' => null]);
        AuditLog::record('home.reset');

        return back()->with('status', 'Home page reset to the default layout.');
    }
}
