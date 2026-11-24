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
            $urls = [['loc' => $this->abs('/')], ['loc' => $this->abs('/blog')]];

            foreach (Page::published()->get(['slug', 'updated_at']) as $p) {
                $urls[] = ['loc' => $this->abs("/{$p->slug}"), 'lastmod' => $p->updated_at];
            }
            foreach (Post::published()->with('category:id,url_prefix')->orderByDesc('published_at')->get(['slug', 'category_id', 'updated_at']) as $p) {
                $urls[] = ['loc' => $this->abs($p->path()), 'lastmod' => $p->updated_at];
            }
            foreach (Category::whereHas('posts', fn ($q) => $q->published())->get(['slug', 'url_prefix']) as $c) {
                $urls[] = ['loc' => $this->abs($c->path())];
            }
            foreach (Tag::whereHas('posts', fn ($q) => $q->published())->get(['slug']) as $t) {
                $urls[] = ['loc' => $this->abs("/tag/{$t->slug}")];
            }
            foreach (User::where('status', 'active')->whereHas('posts', fn ($q) => $q->published())->get(['author_slug']) as $u) {
                $urls[] = ['loc' => $this->abs("/author/{$u->author_slug}")];
            }

            $body = collect($urls)->map(fn ($u) => '<url><loc>'.e($u['loc']).'</loc>'
                .(isset($u['lastmod']) ? '<lastmod>'.$u['lastmod']->toIso8601String().'</lastmod>' : '').'</url>')->implode("\n");

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$body."\n</urlset>\n";
        });

        return $this->xml($xml, 'application/xml');
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /settings', 'Disallow: /dashboard', 'Disallow: /search', '', 'Sitemap: '.$this->abs('/sitemap.xml')];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function rss(): Response
    {
        $xml = PublicCache::remember('rss', function () {
            $site = PublicData::site();
            $posts = Post::published()->inBlog()->select(['id', 'author_id', 'category_id', 'title', 'slug', 'excerpt', 'published_at'])
                ->with(['category:id,name,url_prefix', 'author:id,name,display_name'])->orderByDesc('published_at')->limit(20)->get();

            $items = $posts->map(fn (Post $p) => '<item><title>'.e($p->title).'</title><link>'.e($this->abs($p->path())).'</link>'
                .'<guid isPermaLink="true">'.e($this->abs($p->path())).'</guid><pubDate>'.$p->published_at->toRfc2822String().'</pubDate>'
                .'<dc:creator>'.e($p->author->publicName()).'</dc:creator><category>'.e($p->category->name).'</category>'
                .'<description>'.e((string) $p->excerpt).'</description></item>')->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
                .'<title>'.e($site['name']).'</title><link>'.e($this->abs('/')).'</link><description>'.e($site['tagline'] ?: $site['name']).'</description>'
                .'<language>'.e($site['language']).'</language>'.$items.'</channel></rss>';
        });

        return $this->xml($xml, 'application/rss+xml');
    }

    /** Feeds are cached, so addresses come from APP_URL: a request's Host or scheme must never end up in the cache. */
    private function abs(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /** Own CSP: the site-wide one blocks the inline styles of the browser's XML viewer. Nothing here needs scripts. */
    private function xml(string $body, string $type): Response
    {
        return response($body, 200, [
            'Content-Type' => "{$type}; charset=UTF-8",
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
        ]);
    }
}
