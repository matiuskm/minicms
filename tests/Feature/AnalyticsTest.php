<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\Analytics\Source;
use App\Support\Analytics\Tracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

function visit(string $url = '/', array $headers = [], ?string $ua = BROWSER)
{
    $headers = array_filter(['User-Agent' => $ua, 'Accept' => 'text/html'] + $headers, fn ($v) => $v !== null);

    return test()->withHeaders($headers)->get($url);
}

function views(): int
{
    return DB::table('page_views')->count();
}

beforeEach(function () {
    Post::factory()->status('published')->create(['slug' => 'hello', 'title' => 'Hello']);
    Cache::flush();
});

// ---- source classification -----------------------------------------------------------------------------------

test('TC-F14-02 sources are recognised from utm tags, referrers and in-app browsers', function (?string $ref, array $query, string $ua, string $expected, ?string $campaign = null) {
    $r = Source::classify($ref, $query, 'cms.test', $ua);

    expect($r['source'])->toBe($expected)->and($r['campaign'])->toBe($campaign);
})->with([
    'utm instagram' => [null, ['utm_source' => 'instagram', 'utm_campaign' => 'Launch Week'], BROWSER, 'instagram', 'launch week'],
    'utm alias ig' => [null, ['utm_source' => 'IG'], BROWSER, 'instagram'],
    'utm alias wa' => [null, ['utm_source' => 'wa'], BROWSER, 'whatsapp'],
    'utm beats referrer' => ['https://www.facebook.com/', ['utm_source' => 'whatsapp'], BROWSER, 'whatsapp'],
    'instagram referrer' => ['https://l.instagram.com/?u=x', [], BROWSER, 'instagram'],
    'facebook referrer' => ['https://m.facebook.com/', [], BROWSER, 'facebook'],
    'facebook redirector' => ['https://lm.facebook.com/l.php', [], BROWSER, 'facebook'],
    'x via t.co' => ['https://t.co/abc', [], BROWSER, 'x'],
    'whatsapp web' => ['https://web.whatsapp.com/', [], BROWSER, 'whatsapp'],
    'telegram' => ['https://t.me/channel', [], BROWSER, 'telegram'],
    'google' => ['https://www.google.co.id/', [], BROWSER, 'google'],
    'google com' => ['https://www.google.com/', [], BROWSER, 'google'],
    'bing' => ['https://www.bing.com/search', [], BROWSER, 'bing'],
    'unknown site' => ['https://news.example.org/story', [], BROWSER, 'referral'],
    'own site' => ['https://cms.test/blog/x', [], BROWSER, 'internal'],
    'own site with www' => ['https://www.cms.test/blog/x', [], BROWSER, 'internal'],
    'instagram app' => [null, [], 'Mozilla/5.0 (iPhone) AppleWebKit Mobile/15E148 Instagram 300.0.0', 'instagram'],
    'facebook app' => [null, [], 'Mozilla/5.0 (Linux; Android 13) [FBAN/FB4A;FBAV/450.0]', 'facebook'],
    'tiktok app' => [null, [], 'Mozilla/5.0 (Linux; Android 13) BytedanceWebview/d8a21c6', 'tiktok'],
    'nothing at all' => [null, [], BROWSER, 'direct'],
    'referrer beats app hint' => ['https://www.google.com/', [], 'Instagram 1.0', 'google'],
]);

test('referral hosts are kept and campaign text is sanitised', function () {
    expect(Source::classify('https://blog.example.org/post?x=1', [], 'cms.test'))->toMatchArray(['source' => 'referral', 'referrer_host' => 'blog.example.org']);

    $r = Source::classify(null, ['utm_source' => '<script>alert(1)</script>', 'utm_campaign' => "x'; DROP TABLE posts;--", 'utm_medium' => str_repeat('a', 99)], 'cms.test');
    expect($r['source'])->toMatch('/^[a-z0-9._\- ]{1,20}$/')->and($r['campaign'])->not->toContain("'")->not->toContain(';')->and(strlen($r['medium']))->toBe(30);
    expect(Source::classify(null, ['utm_source' => ['array'], 'utm_campaign' => 123], 'cms.test')['source'])->toBe('direct');
});

// ---- tracking ------------------------------------------------------------------------------------------------

test('TC-F14-01 a public visit is counted once with its page and source', function () {
    visit('/blog/hello?utm_source=ig&utm_campaign=launch')->assertOk();

    $row = DB::table('page_views')->first();
    expect(views())->toBe(1);
    expect($row->page_key)->toBe('post:'.Post::first()->id)->and($row->source)->toBe('instagram')->and($row->campaign)->toBe('launch')->and($row->day)->toBe(now()->toDateString());
});

test('every kind of public page gets a stable key', function () {
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);
    Post::factory()->status('published')->create(['category_id' => $cat->id]);
    Page::factory()->create(['slug' => 'about', 'status' => 'published']);

    foreach (['/', '/blog', '/category/news', '/about'] as $url) {
        visit($url)->assertOk();
    }

    expect(DB::table('page_views')->orderBy('id')->pluck('page_key')->all())->toBe(['home', 'blog', 'category:news', 'page:'.Page::first()->id]);
});

test('the same page is counted again on each load; unique visitors are deduplicated per day', function () {
    visit('/blog/hello');
    visit('/blog/hello');
    visit('/blog/hello', ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');

    expect(views())->toBe(3)->and(DB::table('page_views')->distinct()->count('visitor'))->toBe(2);
});

test('the visitor hash contains no IP or user agent and changes every day', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    visit('/');

    $row = DB::table('page_views')->first();
    $columns = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
    expect($columns)->not->toContain('203.0.113')->not->toContain('Mozilla')->not->toContain('Safari');
    expect(strlen($row->visitor))->toBe(8);

    $today = bin2hex(Tracker::visitor(request()->duplicate(server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => BROWSER])));
    $this->travel(1)->day();
    $tomorrow = bin2hex(Tracker::visitor(request()->duplicate(server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => BROWSER])));
    expect($today)->not->toBe($tomorrow);
});

test('TC-F14-03 bots, link previews, monitors and empty agents are not counted', function () {
    foreach ([
        'Googlebot/2.1 (+http://www.google.com/bot.html)', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'WhatsApp/2.23.20 A', 'facebookexternalhit/1.1',
        'Twitterbot/1.0', 'TelegramBot (like TwitterBot)', 'Slackbot-LinkExpanding 1.0', 'curl/8.4.0', 'python-requests/2.31', 'Go-http-client/1.1',
        'Mozilla/5.0 HeadlessChrome/120.0', 'UptimeRobot/2.0', 'Mozilla/5.0 (compatible; AhrefsBot/7.0)', 'Lighthouse', 'Mozilla/5.0 (compatible; Applebot/0.1)', '',
    ] as $ua) {
        visit('/', ua: $ua === '' ? null : $ua);
    }

    expect(views())->toBe(0);
});

test('staff, prefetches, do-not-track and non-page requests are not counted', function () {
    visit('/', ['DNT' => '1']);
    visit('/', ['Sec-GPC' => '1']);
    visit('/', ['Sec-Purpose' => 'prefetch']);
    visit('/', ['Purpose' => 'prefetch']);
    visit('/', ['Accept' => 'application/json']);
    expect(views())->toBe(0);

    $this->actingAs(User::factory()->create());
    visit('/blog/hello');
    expect(views())->toBe(0);
    auth()->logout();
    $this->flushSession();

    // Not public pages: feeds, search, admin, errors and redirects
    foreach (['/sitemap.xml', '/rss.xml', '/robots.txt', '/theme.css', '/search?q=hello', '/login', '/blog/missing', '/nope'] as $url) {
        visit($url);
    }
    expect(views())->toBe(0);

    $post = Post::first();
    $this->actingAs(adminUser())->put("/admin/posts/{$post->id}", ['action' => 'save', 'title' => 'Hello', 'body' => $post->body, 'category_id' => $post->category_id, 'version' => $post->version, 'slug' => 'renamed']);
    auth()->logout();
    visit('/blog/hello')->assertStatus(301);
    expect(views())->toBe(0);
});

test('inertia navigations count as page views, using the in-site referrer', function () {
    $version = visit('/')->viewData('page')['version'];
    visit('/blog/hello', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml', 'Referer' => url('/')])->assertOk();

    expect(views())->toBe(2)->and(DB::table('page_views')->orderByDesc('id')->value('source'))->toBe('internal');
});

test('tracking can be switched off in settings', function () {
    Setting::put(['analytics_enabled' => false]);

    visit('/');

    expect(views())->toBe(0);
});

test('a failing tracker never breaks the page', function () {
    Schema::drop('page_views');

    visit('/')->assertOk();
});

test('a flood from one client is capped', function () {
    foreach (range(1, 130) as $i) {
        visit('/');
    }

    expect(views())->toBe(120);
    RateLimiter::clear('x');
});

test('old visit records are pruned according to the retention setting', function () {
    DB::table('page_views')->insert([
        ['day' => now()->subDays(100)->toDateString(), 'at' => now()->subDays(100), 'visitor' => str_repeat('a', 8), 'page_key' => 'home', 'source' => 'direct'],
        ['day' => now()->subDays(60)->toDateString(), 'at' => now()->subDays(60), 'visitor' => str_repeat('b', 8), 'page_key' => 'home', 'source' => 'direct'],
        ['day' => now()->toDateString(), 'at' => now(), 'visitor' => str_repeat('c', 8), 'page_key' => 'home', 'source' => 'direct'],
    ]);

    $this->artisan('cms:prune-analytics')->assertSuccessful();
    expect(views())->toBe(2);

    Setting::put(['analytics_retention_days' => 30]);
    $this->artisan('cms:prune-analytics');
    expect(views())->toBe(1);
});

// ---- reports -------------------------------------------------------------------------------------------------

function seedViews(): void
{
    $post = Post::first();
    $rows = [];
    foreach ([['instagram', 'launch', 5], ['whatsapp', null, 3], ['direct', null, 2], ['google', null, 4], ['internal', null, 6]] as [$source, $campaign, $count]) {
        foreach (range(1, $count) as $i) {
            $rows[] = ['day' => now()->toDateString(), 'at' => now(), 'visitor' => substr(md5($source.$i), 0, 8), 'page_key' => "post:{$post->id}", 'source' => $source, 'campaign' => $campaign, 'referrer_host' => null, 'medium' => null];
        }
    }
    foreach (range(1, 3) as $i) {
        $rows[] = ['day' => now()->subDays(2)->toDateString(), 'at' => now()->subDays(2), 'visitor' => substr(md5("home{$i}"), 0, 8), 'page_key' => 'home', 'source' => 'referral', 'campaign' => null, 'referrer_host' => 'news.example.org', 'medium' => null];
    }
    DB::table('page_views')->insert($rows);
}

test('TC-F14-04 the report adds up views, visitors, sources, pages, campaigns and referrers', function () {
    seedViews();

    $this->actingAs(adminUser())->get('/admin/analytics?range=7')->assertOk()->assertInertia(fn ($p) => $p->component('admin/analytics')
        ->where('totals.views', 23)->where('totals.topSource', 'instagram')
        ->where('sources.0.source', 'internal')->where('sources.0.views', 6)
        ->where('sources', fn ($s) => collect($s)->firstWhere('source', 'instagram')['views'] === 5 && collect($s)->firstWhere('source', 'whatsapp')['views'] === 3)
        ->where('pages.0.label', 'Hello')->where('pages.0.views', 20)->where('pages.1.label', 'Home page')
        ->where('campaigns.0.campaign', 'launch')->where('campaigns.0.views', 5)
        ->where('referrers.0.host', 'news.example.org')->where('referrers.0.views', 3)
        ->has('daily', 7)->where('daily.6.views', 20)->where('daily.4.views', 3));
});

test('the report can be narrowed to one page to see where its visitors came from', function () {
    seedViews();
    $key = 'post:'.Post::first()->id;

    $this->actingAs(adminUser())->get("/admin/analytics?page_key={$key}")->assertInertia(fn ($p) => $p->where('totals.views', 20)->where('filter.label', 'Hello')->has('pages', 0)
        ->where('sources', fn ($s) => collect($s)->firstWhere('source', 'instagram')['views'] === 5));
    $this->actingAs(adminUser())->get('/admin/analytics?page_key=home')->assertInertia(fn ($p) => $p->where('totals.views', 3)->where('filter.label', 'Home page'));
});

test('the period and page filter are validated', function () {
    seedViews();
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/analytics?range=9999')->assertInertia(fn ($p) => $p->where('range', 30)->has('daily', 30));
    $this->actingAs($admin)->get('/admin/analytics?range=abc')->assertInertia(fn ($p) => $p->where('range', 30));
    $this->actingAs($admin)->get('/admin/analytics?page_key='.urlencode("x' OR 1=1 --"))->assertOk()->assertInertia(fn ($p) => $p->where('filter', null)->where('totals.views', 23));
});

test('deleted content still shows in the report, labelled as such', function () {
    seedViews();
    Post::first()->forceDelete();

    $this->actingAs(adminUser())->get('/admin/analytics')->assertInertia(fn ($p) => $p->where('pages.0.label', '(deleted)'));
});

test('the link builder offers the home page, pages and recent posts', function () {
    Page::factory()->create(['title' => 'About', 'slug' => 'about', 'status' => 'published']);

    $this->actingAs(adminUser())->get('/admin/analytics')->assertInertia(fn ($p) => $p->where('builder.site', url('/'))
        ->where('builder.targets', fn ($t) => collect($t)->pluck('path')->contains('/blog/hello') && collect($t)->pluck('path')->contains('/about') && $t[0]['path'] === '/'));
});

test('statistics are admin only', function () {
    $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertForbidden();
    auth()->logout();
    $this->get('/admin/analytics')->assertRedirect('/login');
});

test('the report is built from a constant number of queries', function () {
    seedViews();
    Cache::flush();
    DB::enableQueryLog();

    $this->actingAs(adminUser())->get('/admin/analytics')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(14);
});

test('the analytics settings are validated and saved', function () {
    $admin = adminUser();
    $base = ['site_name' => '', 'tagline' => '', 'logo_media_id' => null, 'favicon_media_id' => null, 'content_language' => 'en', 'posts_per_page' => 6, 'cta_label' => '', 'cta_url' => '',
        'pdf_max_mb' => 10, 'mail_from_name' => '', 'mail_from_address' => '', 'maintenance_mode' => false, 'analytics_enabled' => true, 'analytics_retention_days' => 90];

    foreach ([29, 366, 'x'] as $bad) {
        $this->actingAs($admin)->put('/admin/settings', [...$base, 'analytics_retention_days' => $bad])->assertSessionHasErrors('analytics_retention_days');
    }
    $this->actingAs($admin)->put('/admin/settings', [...$base, 'analytics_enabled' => false, 'analytics_retention_days' => 45])->assertSessionHasNoErrors();

    expect(Setting::get('analytics_enabled'))->toBeFalse()->and(Setting::get('analytics_retention_days'))->toBe(45);
});
