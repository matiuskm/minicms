<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Visitor statistics and the campaign-link builder. Admin only (route group). */
class AnalyticsController extends Controller
{
    private const RANGES = [7, 30, 90];

    public function index(Request $request): Response
    {
        $range = (int) $request->query('range', 30);
        $range = in_array($range, self::RANGES, true) ? $range : 30;

        $key = $request->query('page_key');
        $key = is_string($key) && preg_match('/^[a-z]+(:[a-z0-9\-]+)?$/', $key) ? $key : null;

        // One minute of caching keeps the dashboard cheap even on a large log.
        $report = Cache::remember("analytics.report.{$range}.".($key ?? 'all'), 60, fn () => $this->report($range, $key));

        return Inertia::render('admin/analytics', [
            ...$report,
            'range' => $range,
            'ranges' => self::RANGES,
            'filter' => $key ? ['key' => $key, 'label' => $this->label($key, $this->titles([$key]))] : null,
            'enabled' => (bool) Setting::get('analytics_enabled', true),
            'retention' => (int) Setting::get('analytics_retention_days', 90),
            'builder' => [
                'site' => url('/'),
                'targets' => [
                    ['label' => 'Home page', 'path' => '/'],
                    ['label' => 'Blog', 'path' => '/blog'],
                    ...Page::published()->orderBy('title')->limit(50)->get(['title', 'slug'])->map(fn ($p) => ['label' => "Page: {$p->title}", 'path' => "/{$p->slug}"])->all(),
                    ...Post::published()->latest('published_at')->limit(100)->get(['title', 'slug'])->map(fn ($p) => ['label' => $p->title, 'path' => "/blog/{$p->slug}"])->all(),
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function report(int $range, ?string $key): array
    {
        $from = now()->subDays($range - 1)->toDateString();
        $q = fn () => DB::table('page_views')->where('day', '>=', $from)->when($key, fn ($w) => $w->where('page_key', $key));

        $byDay = $q()->selectRaw('day, count(*) as views, count(distinct visitor) as visitors')->groupBy('day')->get()->keyBy('day');
        $daily = collect(range($range - 1, 0))->map(function ($ago) use ($byDay) {
            $day = now()->subDays($ago)->toDateString();

            return ['day' => $day, 'views' => (int) ($byDay[$day]->views ?? 0), 'visitors' => (int) ($byDay[$day]->visitors ?? 0)];
        })->values();

        $sources = $q()->selectRaw('source, count(*) as views, count(distinct day, visitor) as visitors')->groupBy('source')->orderByDesc('views')->get()
            ->map(fn ($r) => ['source' => $r->source, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors]);

        $pages = $key ? collect() : $q()->selectRaw('page_key, count(*) as views, count(distinct day, visitor) as visitors')->groupBy('page_key')->orderByDesc('views')->limit(10)->get();
        $titles = $this->titles($pages->pluck('page_key')->all());

        $external = $sources->whereNotIn('source', ['direct', 'internal']);

        return [
            'totals' => ['views' => $daily->sum('views'), 'visitors' => $daily->sum('visitors'), 'topSource' => $external->first()['source'] ?? null],
            'daily' => $daily,
            'sources' => $sources->values(),
            'pages' => $pages->map(fn ($r) => ['key' => $r->page_key, 'label' => $this->label($r->page_key, $titles), 'views' => (int) $r->views, 'visitors' => (int) $r->visitors])->values(),
            'campaigns' => $q()->whereNotNull('campaign')->selectRaw('source, campaign, count(*) as views')->groupBy('source', 'campaign')->orderByDesc('views')->limit(10)->get()
                ->map(fn ($r) => ['source' => $r->source, 'campaign' => $r->campaign, 'views' => (int) $r->views])->values(),
            'referrers' => $q()->where('source', 'referral')->selectRaw('referrer_host, count(*) as views')->groupBy('referrer_host')->orderByDesc('views')->limit(10)->get()
                ->map(fn ($r) => ['host' => $r->referrer_host, 'views' => (int) $r->views])->values(),
        ];
    }

    /**
     * Titles for post:/page: keys, resolved in two queries however many keys there are.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function titles(array $keys): array
    {
        $ids = fn (string $prefix) => collect($keys)->filter(fn ($k) => str_starts_with($k, "{$prefix}:"))->map(fn ($k) => (int) substr($k, strlen($prefix) + 1))->filter()->values()->all();

        $titles = [];
        if ($post = $ids('post')) {
            foreach (Post::withTrashed()->whereIn('id', $post)->pluck('title', 'id') as $id => $title) {
                $titles["post:{$id}"] = $title;
            }
        }
        if ($page = $ids('page')) {
            foreach (Page::withTrashed()->whereIn('id', $page)->pluck('title', 'id') as $id => $title) {
                $titles["page:{$id}"] = $title;
            }
        }

        return $titles;
    }

    /** @param  array<string, string>  $titles */
    private function label(string $key, array $titles): string
    {
        return match (true) {
            $key === 'home' => 'Home page',
            $key === 'blog' => 'Blog',
            isset($titles[$key]) => $titles[$key],
            str_starts_with($key, 'post:'), str_starts_with($key, 'page:') => '(deleted)',
            default => ucfirst(str_replace(':', ': ', $key)),
        };
    }
}
