<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\PublicCache;
use App\Support\PublicData;
use Illuminate\Http\Response;

/** sitemap.xml, robots.txt and rss.xml (FR-57). Only published content, cached with the rest of the public data. */
class FeedController extends Controller
{
    public function sitemap(): Response
    {
        $xml = PublicCache::remember('sitemap', function () {
            $urls = [['loc' => url('/')], ['loc' => url('/blog')]];

            foreach (Page::published()->get(['slug', 'updated_at']) as $p) {
                $urls[] = ['loc' => url("/{$p->slug}"), 'lastmod' => $p->updated_at];
            }
            foreach (Post::published()->with('category:id,url_prefix')->orderByDesc('published_at')->get(['slug', 'category_id', 'updated_at']) as $p) {
                $urls[] = ['loc' => url($p->path()), 'lastmod' => $p->updated_at];
            }
            foreach (Category::whereHas('posts', fn ($q) => $q->published())->get(['slug', 'url_prefix']) as $c) {
                $urls[] = ['loc' => url($c->path())];
            }
            foreach (Tag::whereHas('posts', fn ($q) => $q->published())->get(['slug']) as $t) {
                $urls[] = ['loc' => url("/tag/{$t->slug}")];
            }
            foreach (User::where('status', 'active')->whereHas('posts', fn ($q) => $q->published())->get(['author_slug']) as $u) {
                $urls[] = ['loc' => url("/author/{$u->author_slug}")];
            }

            $body = collect($urls)->map(fn ($u) => '<url><loc>'.e($u['loc']).'</loc>'
                .(isset($u['lastmod']) ? '<lastmod>'.$u['lastmod']->toIso8601String().'</lastmod>' : '').'</url>')->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /settings', 'Disallow: /login', 'Disallow: /dashboard', 'Disallow: /search', '', 'Sitemap: '.url('/sitemap.xml')];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function rss(): Response
    {
        $xml = PublicCache::remember('rss', function () {
            $site = PublicData::site();
            $posts = Post::published()->inBlog()->select(['id', 'author_id', 'category_id', 'title', 'slug', 'excerpt', 'published_at'])
                ->with(['category:id,name,url_prefix', 'author:id,name,display_name'])->orderByDesc('published_at')->limit(20)->get();

            $items = $posts->map(fn (Post $p) => '<item><title>'.e($p->title).'</title><link>'.e(url($p->path())).'</link>'
                .'<guid isPermaLink="true">'.e(url($p->path())).'</guid><pubDate>'.$p->published_at->toRfc2822String().'</pubDate>'
                .'<dc:creator>'.e($p->author->publicName()).'</dc:creator><category>'.e($p->category->name).'</category>'
                .'<description>'.e((string) $p->excerpt).'</description></item>')->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
                .'<title>'.e($site['name']).'</title><link>'.e(url('/')).'</link><description>'.e($site['tagline'] ?: $site['name']).'</description>'
                .'<language>'.e($site['language']).'</language>'.$items.'</channel></rss>';
        });

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
