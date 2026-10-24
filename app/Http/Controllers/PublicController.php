<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\User;
use App\Support\ContentRenderer;
use App\Support\PublicCache;
use App\Support\PublicData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public site (F-09). Everything here is read-only, limited to published content (BR-28),
 * built from a constant number of queries, and cached per URL until content changes.
 */
class PublicController extends Controller
{
    /** Posts shown in the home hero block: one feature plus the "Latest post" list. */
    private const HERO_POSTS = 5;

    public function home(Request $request): Response
    {
        $page = $this->pageNumber($request);

        $data = PublicCache::remember("home.{$page}", function () use ($page) {
            $per = PublicData::perPage();
            $total = Post::published()->count();
            $gridTotal = max(0, $total - self::HERO_POSTS);
            $last = max(1, (int) ceil($gridTotal / $per));

            if ($page > $last) {
                return null;
            }

            // One query for the slice, hero and grid together.
            $skip = $page === 1 ? 0 : self::HERO_POSTS + ($page - 1) * $per;
            $take = $page === 1 ? self::HERO_POSTS + $per : $per;
            $posts = $this->published()->skip($skip)->take($take)->get()->map(fn (Post $p) => PublicData::card($p));

            return [
                'featured' => $page === 1 ? $posts->first() : null,
                'latest' => $page === 1 ? $posts->slice(1, self::HERO_POSTS - 1)->values() : [],
                'posts' => $page === 1 ? $posts->slice(self::HERO_POSTS)->values() : $posts,
                'pager' => PublicData::pager([$page, $last], '/'),
            ];
        });

        abort_if($data === null, 404);
        $request->attributes->set('analytics.key', 'home');

        return $this->render('public/home', $data, ['title' => null, 'description' => null], $request, canonical: $page > 1 ? "/?page={$page}" : '/');
    }

    public function blog(Request $request): Response
    {
        return $this->archive($request, 'Blog', 'All posts', fn ($q) => $q, '/blog', 'blog');
    }

    public function category(Request $request, string $slug): Response|RedirectResponse
    {
        $category = Category::where('slug', $slug)->with('children:id,parent_id')->first();
        if (! $category) {
            return $this->redirectOrFail($request);
        }

        $ids = [$category->id, ...$category->children->pluck('id')];

        return $this->archive($request, $category->name, $category->description, fn ($q) => $q->whereIn('category_id', $ids), "/category/{$slug}", "category.{$slug}");
    }

    public function tag(Request $request, string $slug): Response|RedirectResponse
    {
        $tag = Tag::where('slug', $slug)->first();
        if (! $tag) {
            return $this->redirectOrFail($request);
        }

        return $this->archive($request, "Tagged: {$tag->name}", null, fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tag->id)), "/tag/{$slug}", "tag.{$slug}");
    }

    public function author(Request $request, string $slug): Response|RedirectResponse
    {
        $author = User::where('author_slug', $slug)->where('status', 'active')->first(['id', 'name', 'display_name', 'author_slug']);
        if (! $author) {
            return $this->redirectOrFail($request);
        }

        return $this->archive($request, $author->publicName(), null, fn ($q) => $q->where('author_id', $author->id), "/author/{$slug}", "author.{$slug}");
    }

    public function search(Request $request): Response
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $page = $this->pageNumber($request);
        $per = PublicData::perPage();

        $posts = null;
        if ($q !== '') {
            // Boolean-mode terms reduced to letters and digits (no operators can get through); titles also match by substring so short and non-spaced
            // (CJK) queries work despite the fulltext token limits.
            $terms = collect(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $q), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($t) => "+{$t}*")->implode(' ');

            $posts = $this->published()
                ->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($q, '%_\\').'%')
                    ->when($terms !== '', fn ($w) => $w->orWhereRaw('MATCH(title, excerpt, body) AGAINST (? IN BOOLEAN MODE)', [$terms])))
                ->paginate($per, ['*'], 'page', $page)->withQueryString();
        }

        return $this->render('public/archive', [
            'heading' => $q === '' ? 'Search' : "Results for “{$q}”",
            'intro' => null,
            'search' => $q,
            'posts' => $posts?->getCollection()->map(fn (Post $p) => PublicData::card($p))->values() ?? [],
            'pager' => $posts ? PublicData::pager($posts, '/search', ['q' => $q]) : null,
        ], ['title' => 'Search', 'noindex' => true], $request, canonical: '/search');
    }

    public function post(Request $request, string $slug): Response|RedirectResponse
    {
        $data = PublicCache::remember("post.{$slug}", function () use ($slug) {
            $post = Post::published()->where('slug', $slug)->with([...PublicData::POST_WITH, 'tags:id,name,slug'])->first();

            return $post ? [
                'post' => [
                    ...PublicData::card($post),
                    'body' => ContentRenderer::render($post->body),
                    'tags' => $post->tags->map(fn (Tag $t) => ['name' => $t->name, 'url' => "/tag/{$t->slug}"])->all(),
                ],
                'seo' => [
                    'title' => $post->meta_title ?: $post->title,
                    'description' => $post->meta_description ?: $post->excerpt ?: Str::limit(trim(strip_tags($post->body)), 155),
                    'image' => $post->featuredMedia?->url('large'),
                    'type' => 'article',
                    'published' => $post->published_at->toIso8601String(),
                    'modified' => $post->updated_at->toIso8601String(),
                    'author' => $post->author->publicName(),
                ],
            ] : null;
        });

        if ($data === null) {
            return $this->redirectOrFail($request);
        }

        $request->attributes->set('analytics.key', 'post:'.$data['post']['id']);

        return $this->render('public/post', ['post' => $data['post']], $data['seo'], $request, canonical: "/blog/{$slug}");
    }

    public function page(Request $request, string $slug): Response|RedirectResponse
    {
        $data = PublicCache::remember("page.{$slug}", function () use ($slug) {
            $page = Page::published()->where('slug', $slug)->first();

            return $page ? [
                'page' => ['id' => $page->id, 'title' => $page->title, 'body' => ContentRenderer::render($page->body)],
                'seo' => [
                    'title' => $page->meta_title ?: $page->title,
                    'description' => $page->meta_description ?: Str::limit(trim(strip_tags($page->body)), 155),
                    'type' => 'website',
                ],
            ] : null;
        });

        if ($data === null) {
            return $this->redirectOrFail($request);
        }

        $request->attributes->set('analytics.key', 'page:'.$data['page']['id']);

        return $this->render('public/page', ['page' => $data['page']], $data['seo'], $request, canonical: "/{$slug}");
    }

    private function archive(Request $request, string $heading, ?string $intro, callable $scope, string $path, string $cacheKey): Response
    {
        $page = $this->pageNumber($request);
        $request->attributes->set('analytics.key', str_replace('.', ':', $cacheKey));

        $data = PublicCache::remember("archive.{$cacheKey}.{$page}", function () use ($scope, $path, $page, $heading, $intro) {
            $posts = $scope($this->published())->paginate(PublicData::perPage(), ['*'], 'page', $page);

            return $posts->isEmpty() && $page > 1 ? null : [
                'heading' => $heading,
                'intro' => $intro,
                'posts' => $posts->getCollection()->map(fn (Post $p) => PublicData::card($p))->values(),
                'pager' => PublicData::pager($posts, $path),
            ];
        });

        abort_if($data === null, 404);

        return $this->render('public/archive', $data, ['title' => $heading, 'description' => $intro], $request, canonical: $page > 1 ? "{$path}?page={$page}" : $path);
    }

    /** Published posts, newest first, with just the columns and relations the cards use. */
    private function published()
    {
        return Post::published()->select(PublicData::POST_COLUMNS)->with(PublicData::POST_WITH)
            ->orderByDesc('published_at')->orderByDesc('id');
    }

    private function pageNumber(Request $request): int
    {
        return max(1, min(10000, (int) $request->query('page', 1)));
    }

    /** An old address recorded when a slug changed (BR-12); anything else is a plain 404. */
    private function redirectOrFail(Request $request): RedirectResponse
    {
        $to = Redirect::where('from_path', '/'.$request->path())->value('to_path');
        abort_unless($to, 404);

        return redirect($to, 301);
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $seo
     */
    private function render(string $component, array $props, array $seo, Request $request, string $canonical): Response
    {
        $site = PublicData::site();
        $title = $seo['title'] ?? null;
        $seo += ['type' => 'website', 'noindex' => false];
        $seo['description'] ??= $site['tagline'];

        return Inertia::render($component, [
            ...$props,
            'site' => $site,
            'theme' => PublicData::theme(),
            'seo' => [
                ...$seo,
                'title' => $title ? "{$title} — {$site['name']}" : ($site['tagline'] ? "{$site['name']} — {$site['tagline']}" : $site['name']),
                'canonical' => url($canonical),
                'image' => isset($seo['image']) ? url($seo['image']) : null,
                'jsonld' => $this->jsonLd($seo, $site, url($canonical)),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function jsonLd(array $seo, array $site, string $url): ?array
    {
        if (($seo['type'] ?? null) !== 'article') {
            return null;
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $seo['title'],
            'description' => $seo['description'] ?? null,
            'image' => isset($seo['image']) ? [url($seo['image'])] : null,
            'datePublished' => $seo['published'] ?? null,
            'dateModified' => $seo['modified'] ?? null,
            'author' => ['@type' => 'Person', 'name' => $seo['author'] ?? $site['name']],
            'publisher' => ['@type' => 'Organization', 'name' => $site['name']],
            'mainEntityOfPage' => $url,
        ]);
    }
}
