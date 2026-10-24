<?php

namespace App\Support;

use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Pagination\LengthAwarePaginator;

/** Shapes models into the small arrays the public pages need. Callers eager-load the relations used here. */
class PublicData
{
    /** Columns every public post list needs; the body is deliberately left out. */
    public const POST_COLUMNS = ['id', 'author_id', 'category_id', 'featured_media_id', 'title', 'slug', 'excerpt', 'reading_minutes', 'published_at', 'updated_at'];

    /** Relations to eager-load for cards (keeps public pages at a constant query count). */
    public const POST_WITH = ['category:id,name,slug', 'author:id,name,display_name,author_slug', 'featuredMedia'];

    /** @return array<string, mixed> */
    public static function site(): array
    {
        return PublicCache::remember('site', function () {
            $s = Setting::everything();
            $ids = array_filter([$s['logo_media_id'], $s['favicon_media_id']]);
            $media = $ids ? Media::whereIn('id', $ids)->get()->keyBy('id') : collect();

            return [
                'name' => $s['site_name'] ?: config('app.name'),
                'tagline' => $s['tagline'],
                'language' => $s['content_language'],
                'logo' => ($m = $media[$s['logo_media_id']] ?? null) ? ['src' => $m->url('medium'), 'alt' => $m->alt_text] : null,
                'favicon' => ($m = $media[$s['favicon_media_id']] ?? null) ? $m->url('thumb') : null,
                'cta' => $s['cta_label'] && $s['cta_url'] ? ['label' => $s['cta_label'], 'url' => $s['cta_url']] : null,
                'menu' => MenuItem::resolvedAll(),
            ];
        });
    }

    /** @return array{v: int, fonts: list<string>} stylesheet cache-buster and the font entries the page must load */
    public static function theme(): array
    {
        return PublicCache::remember('theme', fn () => ['v' => PublicCache::version(), 'fonts' => ThemeCss::fontEntries()]);
    }

    public static function perPage(): int
    {
        return max(5, min(50, (int) Setting::get('posts_per_page', 6)));
    }

    /** @return array<string, mixed> */
    public static function card(Post $post): array
    {
        $at = $post->published_at->timezone(config('cms.timezone'));
        $media = $post->featuredMedia;

        return [
            'id' => $post->id,
            'title' => $post->title,
            'url' => "/blog/{$post->slug}",
            'excerpt' => $post->excerpt,
            'date' => $at->format('M j'),
            'date_long' => $at->format('F j, Y'),
            'date_iso' => $post->published_at->toIso8601String(),
            'reading_minutes' => $post->reading_minutes,
            'category' => ['name' => $post->category->name, 'url' => "/category/{$post->category->slug}"],
            'author' => ['name' => $post->author->publicName(), 'url' => "/author/{$post->author->author_slug}"],
            'image' => $media ? ['large' => $media->url('large'), 'medium' => $media->url('medium'), 'thumb' => $media->url('thumb'), 'alt' => $media->alt_text] : null,
        ];
    }

    /**
     * Page numbers around the current page, as the design shows them (1 2 3 4 5).
     *
     * @return array{current: int, last: int, prev: ?string, next: ?string, pages: list<array{n: int, url: string}>}
     */
    public static function pager(LengthAwarePaginator|array $source, string $path, array $query = []): array
    {
        [$current, $last] = $source instanceof LengthAwarePaginator ? [$source->currentPage(), $source->lastPage()] : $source;

        $url = fn (int $n) => rtrim($path.'?'.http_build_query([...$query, ...($n > 1 ? ['page' => $n] : [])]), '?');

        $from = max(1, min($current - 2, $last - 4));
        $to = min($last, $from + 4);

        return [
            'current' => $current,
            'last' => $last,
            'prev' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $last ? $url($current + 1) : null,
            'pages' => collect(range($from, $to))->map(fn ($n) => ['n' => $n, 'url' => $url($n)])->all(),
        ];
    }
}
