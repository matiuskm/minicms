<?php

use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function live(array $attrs = [], int $count = 1)
{
    return Post::factory()->count($count)->status('published')->create($attrs);
}

function queries(string $url): int
{
    // Visit counting adds a few deferred queries after the response; this test measures page rendering only.
    Setting::put(['analytics_enabled' => false]);
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();

    return count(DB::getQueryLog());
}

test('TC-F09-01 home, post, page and archives render published content', function () {
    $author = User::factory()->create(['name' => 'Wendy Real', 'display_name' => 'Midnight Pen']);
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);
    $post = live(['title' => 'Hello World', 'slug' => 'hello-world', 'author_id' => $author->id, 'category_id' => $cat->id, 'body' => '<p>Body text</p>'])->first();
    $post->tags()->attach($tag->id);
    Page::factory()->create(['title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => '<p>About us</p>']);

    $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->component('public/home')->where('sections.0.featured.title', 'Hello World')->where('sections.0.featured.author.name', 'Midnight Pen'));
    $this->get('/blog/hello-world')->assertOk()->assertInertia(fn ($p) => $p->component('public/post')->where('post.title', 'Hello World')->where('post.body', '<p>Body text</p>')->where('post.tags.0.name', 'PHP'));
    $this->get('/about')->assertOk()->assertInertia(fn ($p) => $p->component('public/page')->where('page.title', 'About'));
    $this->get('/category/news')->assertOk()->assertInertia(fn ($p) => $p->component('public/archive')->has('posts', 1));
    $this->get('/tag/php')->assertOk()->assertInertia(fn ($p) => $p->has('posts', 1));
    $this->get("/author/{$author->fresh()->author_slug}")->assertOk()->assertInertia(fn ($p) => $p->where('heading', 'Midnight Pen')->has('posts', 1));
    $this->get('/blog')->assertOk()->assertInertia(fn ($p) => $p->has('posts', 1));
});

test('TC-F09-02 unpublished, scheduled, future, archived and trashed content is not public', function () {
    $hidden = [
        Post::factory()->status('draft')->create(['slug' => 'a-draft']),
        Post::factory()->status('pending_review')->create(['slug' => 'a-review']),
        Post::factory()->status('rejected')->create(['slug' => 'a-rejected']),
        Post::factory()->status('scheduled')->create(['slug' => 'a-scheduled', 'published_at' => now()->addDay()]),
        Post::factory()->status('published')->create(['slug' => 'a-future', 'published_at' => now()->addHour()]),
        Post::factory()->status('archived')->create(['slug' => 'an-archived']),
    ];
    $trashed = Post::factory()->status('published')->create(['slug' => 'a-trashed']);
    $trashed->delete();
    Page::factory()->create(['slug' => 'draft-page', 'status' => 'draft']);
    live(['slug' => 'visible']);

    foreach ([...collect($hidden)->pluck('slug'), 'a-trashed'] as $slug) {
        $this->get("/blog/{$slug}")->assertNotFound();
    }
    $this->get('/draft-page')->assertNotFound();
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.url', '/blog/visible')->has('sections.0.latest', 0)->has('sections.1.posts', 0));
    $this->get('/sitemap.xml')->assertOk()->assertSee('/blog/visible', false)->assertDontSee('a-draft')->assertDontSee('a-future')->assertDontSee('an-archived')->assertDontSee('a-trashed');
    $this->get('/search?q=visible')->assertOk();
});

test('TC-F09-03 public pages use a constant number of queries however many posts exist', function () {
    live([], 10);
    $small = [queries('/'), queries('/blog'), queries('/category/uncategorized')];

    live([], 60);
    $large = [queries('/'), queries('/blog'), queries('/category/uncategorized')];

    expect($large)->toBe($small);
    // The home page loads its feature block and its blog list separately, each with its relations.
    expect(max($large))->toBeLessThanOrEqual(10);

    $post = Post::published()->first();
    expect(queries("/blog/{$post->slug}"))->toBeLessThanOrEqual(8);
});

test('the home hero takes five posts and the grid paginates the rest', function () {
    Setting::put(['posts_per_page' => 5]);
    foreach (range(1, 17) as $i) {
        live(['title' => "Post {$i}", 'published_at' => now()->subMinutes(100 - $i)]);
    }

    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.title', 'Post 17')->has('sections.0.latest', 4)->has('sections.1.posts', 5)->where('pager.last', 3)->where('pager.next', '/?page=2'));
    $this->get('/?page=2')->assertInertia(fn ($p) => $p->has('sections', 1)->where('sections.0.type', 'blog')->has('sections.0.posts', 5)->where('sections.0.posts.0.title', 'Post 7'));
    $this->get('/?page=3')->assertInertia(fn ($p) => $p->has('sections.0.posts', 2));
    $this->get('/?page=4')->assertNotFound();
});

test('a post page renders embeds as sandboxed iframes and keeps images lazy', function () {
    live(['slug' => 'media', 'body' => '<p>x</p><div data-embed="youtube" data-id="dQw4w9WgXcQ"></div><figure><img src="/storage/uploads/2026/10/a.jpg" alt="a"></figure>']);

    $body = $this->get('/blog/media')->viewData('page')['props']['post']['body'];

    expect($body)->toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"')->toContain('sandbox="allow-scripts allow-same-origin allow-presentation"')
        ->toContain('loading="lazy"')->not->toContain('data-embed');
});

test('visitors never receive an email address or a real name when a display name is set', function () {
    $author = User::factory()->create(['name' => 'Wendy Real', 'display_name' => 'Midnight Pen', 'email' => 'wendy.secret@example.com']);
    live(['author_id' => $author->id, 'slug' => 'bylines']);

    foreach (['/', '/blog', '/blog/bylines', "/author/{$author->author_slug}", '/rss.xml', '/sitemap.xml'] as $url) {
        $html = $this->get($url)->getContent();
        expect($html)->not->toContain('wendy.secret')->not->toContain('Wendy Real');
    }
    expect($author->author_slug)->not->toContain('wendy')->and($author->author_slug)->toBe('midnight-pen');
});

test('author archives resolve by slug, never by id, and old addresses redirect after a rename', function () {
    $author = User::factory()->create(['display_name' => 'Old Name']);
    live(['author_id' => $author->id]);
    $old = $author->author_slug;

    $this->get("/author/{$author->id}")->assertNotFound();

    $author->update(['display_name' => 'New Name']);
    expect($author->fresh()->author_slug)->toBe('new-name');
    $this->get("/author/{$old}")->assertRedirect('/author/new-name')->assertStatus(301);
    $this->get('/author/new-name')->assertOk();
});

test('disabled authors have no public archive', function () {
    $author = User::factory()->disabled()->create();
    live(['author_id' => $author->id]);

    $this->get("/author/{$author->author_slug}")->assertNotFound();
});

test('content changes show up immediately because the cache is invalidated', function () {
    $post = live(['title' => 'First', 'slug' => 'cached'])->first();
    $this->get('/blog/cached')->assertInertia(fn ($p) => $p->where('post.title', 'First'));
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.title', 'First'));

    $post->update(['title' => 'Second']);
    $this->get('/blog/cached')->assertInertia(fn ($p) => $p->where('post.title', 'Second'));
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.title', 'Second'));

    live(['title' => 'Newest', 'published_at' => now()]);
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.title', 'Newest'));

    Setting::put(['tagline' => 'A fresh tagline']);
    $this->get('/')->assertInertia(fn ($p) => $p->where('site.tagline', 'A fresh tagline'));

    $post->delete();
    $this->get('/blog/cached')->assertNotFound();
});

test('tag changes made by syncing are visible straight away', function () {
    $post = live(['slug' => 'tagged'])->first();
    $this->get('/blog/tagged')->assertInertia(fn ($p) => $p->has('post.tags', 0));

    $post = $post->fresh();
    $this->actingAs(adminUser())->put("/admin/posts/{$post->id}", ['action' => 'save', 'title' => $post->title, 'body' => $post->body, 'category_id' => $post->category_id,
        'version' => $post->version, 'tag_ids' => [Tag::create(['name' => 'New', 'slug' => 'new'])->id]])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get('/blog/tagged')->assertInertia(fn ($p) => $p->has('post.tags', 1));
});

test('TC-F06-06 an old post address 301-redirects to the new one', function () {
    $post = live(['slug' => 'old-address'])->first()->fresh();
    $this->actingAs(adminUser())->put("/admin/posts/{$post->id}", ['action' => 'save', 'title' => $post->title, 'body' => $post->body, 'category_id' => $post->category_id, 'version' => 1, 'slug' => 'new-address']);
    auth()->logout();

    $this->get('/blog/old-address')->assertStatus(301)->assertRedirect('/blog/new-address');
    $this->get('/blog/new-address')->assertOk();
    expect(Redirect::count())->toBe(1);
});

test('TC-F09-04 sitemap, robots and rss only describe published content', function () {
    live(['title' => 'Fish & Chips', 'slug' => 'fish']);
    Page::factory()->create(['slug' => 'about', 'status' => 'published']);
    Post::factory()->create(['title' => 'Secret draft', 'slug' => 'secret']);

    $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect(simplexml_load_string($sitemap->getContent()))->not->toBeFalse();
    $sitemap->assertSee('/blog/fish', false)->assertSee('/about', false)->assertDontSee('secret');

    $rss = $this->get('/rss.xml')->assertOk();
    expect(simplexml_load_string($rss->getContent()))->not->toBeFalse();
    $rss->assertSee('Fish &amp; Chips', false)->assertDontSee('Secret draft');

    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: ', false);
});

test('SEO meta: titles, descriptions, canonical, article schema', function () {
    Setting::put(['site_name' => 'Essos', 'tagline' => 'Stories']);
    $post = live(['slug' => 'seo', 'title' => 'Plain title', 'meta_title' => 'Custom SEO title', 'meta_description' => 'A custom description', 'excerpt' => 'ignored excerpt'])->first();

    $this->get('/blog/seo')->assertInertia(fn ($p) => $p->where('seo.title', 'Custom SEO title — Essos')->where('seo.description', 'A custom description')
        ->where('seo.canonical', url('/blog/seo'))->where('seo.type', 'article')->where('seo.jsonld.@type', 'Article')->where('seo.jsonld.author.name', $post->author->publicName()));
    $this->get('/')->assertInertia(fn ($p) => $p->where('seo.title', 'Essos — Stories'));

    $post->update(['meta_title' => null, 'meta_description' => null]);
    $this->get('/blog/seo')->assertInertia(fn ($p) => $p->where('seo.title', 'Plain title — Essos')->where('seo.description', 'ignored excerpt'));
    $this->get('/blog/seo')->assertSee('application/ld+json', false);
});

test('JSON-LD cannot break out of its script tag', function () {
    live(['slug' => 'xss', 'title' => 'Hi </script><script>alert(1)</script>']);

    $html = $this->get('/blog/xss')->getContent();

    expect(substr_count(strtolower($html), '<script type="application/ld+json">'))->toBe(1);
    expect($html)->not->toContain('</script><script>alert(1)');
});

test('TC-F09-05 errors are generic pages without stack traces', function () {
    $this->get('/blog/does-not-exist')->assertNotFound()->assertInertia(fn ($p) => $p->component('error')->where('status', 404));
    $this->get('/nope-nope')->assertNotFound()->assertInertia(fn ($p) => $p->component('error'));

    config(['app.debug' => false]);
    Route::get('/__boom', fn () => throw new RuntimeException('secret detail /var/www/app'));
    $res = $this->get('/__boom')->assertStatus(500);
    expect($res->getContent())->not->toContain('secret detail')->not->toContain('/var/www');
    $res->assertInertia(fn ($p) => $p->component('error')->where('status', 500));
});

test('TC-F09-06 search finds titles and body text, hides drafts, and is rate limited', function () {
    live(['title' => 'Learning Laravel', 'slug' => 'laravel', 'body' => '<p>Eloquent relationships explained</p>']);
    live(['title' => 'Cooking', 'slug' => 'cooking', 'body' => '<p>Pasta recipes</p>']);
    Post::factory()->create(['title' => 'Laravel draft', 'slug' => 'laravel-draft']);
    live(['title' => '日本語の記事', 'slug' => 'ja', 'body' => '<p>こんにちは</p>']);

    $this->get('/search?q=laravel')->assertInertia(fn ($p) => $p->has('posts', 1)->where('posts.0.title', 'Learning Laravel'));
    $this->get('/search?q='.urlencode('日本語'))->assertInertia(fn ($p) => $p->has('posts', 1));
    $this->get('/search?q=zzzznothing')->assertInertia(fn ($p) => $p->has('posts', 0));
    $this->get('/search?q=')->assertOk();
    foreach (['"', '+-*', '(laravel', 'laravel*~<>', '%', '_'] as $odd) {
        $this->get('/search?q='.urlencode($odd))->assertOk();
    }
    $this->get('/search?q=laravel')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn ($p) => $p->where('seo.noindex', true));

    foreach (range(1, 60) as $i) {
        $this->get('/search?q=x');
    }
    $this->get('/search?q=x')->assertStatus(429);
});

test('the admin area and account screens are marked noindex', function () {
    foreach (['/login', '/forgot-password'] as $url) {
        $this->get($url)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
    $this->actingAs(adminUser())->get('/admin/users')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/')->assertHeaderMissing('X-Robots-Tag');
});

test('the public header knows about the signed-in user and the footer menus come from settings', function () {
    live();
    $this->get('/')->assertInertia(fn ($p) => $p->where('auth.user', null));
    $this->actingAs($u = User::factory()->create())->get('/')->assertInertia(fn ($p) => $p->where('auth.user', ['id' => $u->id])); // only that someone is signed in, never who
});

test('a reserved path is never treated as a page and unknown pages 404', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/Some_Weird-Slug')->assertNotFound();
    expect(Media::count())->toBe(0);
});

test('FR-55 search also matches body text through the fulltext index', function () {
    // InnoDB fulltext only sees committed rows, so this test commits and cleans up after itself.
    live(['title' => 'Unrelated title', 'slug' => 'ft', 'body' => '<p>Eloquent relationships explained</p>']);
    DB::commit();

    try {
        $this->get('/search?q=relationships')->assertInertia(fn ($p) => $p->has('posts', 1)->where('posts.0.title', 'Unrelated title'));
        $this->get('/search?q=relation')->assertInertia(fn ($p) => $p->has('posts', 1)); // prefix match
    } finally {
        DB::table('posts')->delete();
        DB::table('post_tag')->delete();
        DB::table('revisions')->delete();
        DB::table('users')->delete();
        DB::beginTransaction();
    }
});

test('a category marked hide-from-blog stays out of home, blog and rss but keeps its own page', function () {
    $work = Category::create(['name' => 'Work', 'slug' => 'work', 'hide_from_blog' => true]);
    live(['title' => 'Nota Kue project', 'slug' => 'nota-kue', 'category_id' => $work->id]);
    live(['title' => 'Regular essay', 'slug' => 'essay']);

    foreach (['/', '/blog', '/rss.xml'] as $url) {
        $this->get($url)->assertOk()->assertSee('Regular essay')->assertDontSee('Nota Kue project');
    }

    $this->get('/category/work')->assertOk()->assertSee('Nota Kue project')->assertDontSee('Regular essay');
    $this->get('/blog/nota-kue')->assertOk();
});

test('a category with list layout tells its page to render one card per row', function () {
    $work = Category::create(['name' => 'Work', 'slug' => 'work', 'list_layout' => true]);
    live(['category_id' => $work->id]);

    $this->get('/category/work')->assertOk()->assertInertia(fn ($p) => $p->where('list', true));
    $this->get('/blog')->assertOk()->assertInertia(fn ($p) => $p->where('list', false));
});

test('a category with a url prefix serves its posts and its page under that prefix, and forwards the old addresses', function () {
    $works = Category::create(['name' => 'Works', 'slug' => 'works', 'url_prefix' => 'works']);
    live(['title' => 'Nota Kue', 'slug' => 'nota-kue', 'category_id' => $works->id]);
    live(['title' => 'Regular essay', 'slug' => 'essay']);

    $this->get('/works/nota-kue')->assertOk()->assertInertia(fn ($p) => $p->component('public/post')->where('post.url', '/works/nota-kue')->where('post.category.url', '/works'));
    $this->get('/works')->assertOk()->assertInertia(fn ($p) => $p->component('public/archive')->has('posts', 1));
    $this->get('/blog/essay')->assertOk();

    // Old addresses keep working (query string, e.g. utm_*, included).
    $this->get('/blog/nota-kue?utm_source=whatsapp')->assertRedirect('/works/nota-kue?utm_source=whatsapp')->assertStatus(301);
    $this->get('/category/works')->assertRedirect('/works')->assertStatus(301);
    $this->get('/old/nota-kue')->assertRedirect('/works/nota-kue');
    $this->get('/works/missing')->assertNotFound();

    $this->get('/sitemap.xml')->assertSee('/works/nota-kue', false)->assertSee('/blog/essay', false)->assertDontSee('/blog/nota-kue', false);
    $this->get('/rss.xml')->assertSee('/works/nota-kue', false);
});

test('a url prefix cannot shadow an existing route, page or other prefix', function () {
    $admin = adminUser();
    Page::factory()->create(['slug' => 'about', 'status' => 'published']);
    Category::create(['name' => 'Taken', 'slug' => 'taken', 'url_prefix' => 'taken']);

    foreach (['blog', 'admin', 'category', 'about', 'taken'] as $path) {
        $this->actingAs($admin)->post('/admin/categories', ['name' => "Try {$path}", 'url_prefix' => $path])->assertSessionHasErrors('url_prefix');
    }

    $this->actingAs($admin)->post('/admin/categories', ['name' => 'Works', 'url_prefix' => 'works'])->assertSessionHasNoErrors();
    expect(Category::where('url_prefix', 'works')->exists())->toBeTrue();
});

test('cards on a list-layout category page carry their tags, other pages do not load them', function () {
    $work = Category::create(['name' => 'Works', 'slug' => 'works', 'list_layout' => true]);
    $post = live(['category_id' => $work->id])->first();
    $post->tags()->attach(Tag::create(['name' => 'Laravel', 'slug' => 'laravel'])->id);

    $this->get('/category/works')->assertOk()->assertInertia(fn ($p) => $p->where('posts.0.tags.0.name', 'Laravel'));
    $this->get('/blog')->assertOk()->assertInertia(fn ($p) => $p->where('posts.0.tags', []));
});
