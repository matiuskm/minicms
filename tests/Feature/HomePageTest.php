<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\HomePage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function homeLive(array $attrs = [], int $count = 1)
{
    return Post::factory()->count($count)->status('published')->create($attrs);
}

function homeQueries(string $url): int
{
    Setting::put(['analytics_enabled' => false]);
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();

    return count(DB::getQueryLog());
}

function img(string $mime = 'image/jpeg'): Media
{
    static $n = 0;
    $n++;

    return Media::create(['uuid' => (string) Str::uuid(), 'uploader_id' => adminUser()->id, 'path' => "uploads/2026/10/h{$n}.jpg", 'original_name' => "h{$n}.jpg", 'mime' => $mime, 'size' => 1, 'alt_text' => 'alt']);
}

function sec(string $type, array $extra = []): array
{
    return ['id' => $type.'-'.substr(md5(json_encode($extra).$type), 0, 6), 'type' => $type, 'enabled' => true, ...$extra];
}

function layoutWith(array ...$sections): array
{
    return ['sections' => [...$sections, sec('blog', ['title' => ''])]];
}

test('TC-F16-01 the default layout is the feature block followed by the blog list', function () {
    expect(array_column(HomePage::layout(), 'type'))->toBe(['featured', 'blog']);
    homeLive([], 3);
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.type', 'featured')->where('sections.1.type', 'blog'));
});

test('TC-F16-02 only an admin can open or save the home page layout', function () {
    $this->get('/admin/home-page')->assertRedirect('/login');
    $writer = User::factory()->create();
    $this->actingAs($writer)->get('/admin/home-page')->assertForbidden();
    $this->actingAs($writer)->put('/admin/home-page', layoutWith())->assertForbidden();
    expect(Setting::get('home_sections'))->toBeNull();
});

test('TC-F16-03 an admin saves a layout and the public page follows it', function () {
    homeLive(['title' => 'Only post']);
    $m = img();
    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(
        sec('image', ['media_id' => $m->id, 'title' => 'Welcome', 'text' => 'Hi', 'align' => 'center', 'button_label' => 'Read', 'button_url' => '/blog']),
        sec('cta', ['title' => 'Join', 'button_label' => 'Go', 'button_url' => 'https://example.com/x']),
        sec('featured'),
    ))->assertSessionHasNoErrors();

    $this->get('/')->assertInertia(fn ($p) => $p
        ->where('sections.0.type', 'image')->where('sections.0.title', 'Welcome')->where('sections.0.align', 'center')
        ->where('sections.0.button.url', '/blog')->where('sections.0.image.large', $m->url('large'))
        ->where('sections.1.type', 'cta')->where('sections.1.button.external', true)
        ->where('sections.2.type', 'featured')->where('sections.2.featured.title', 'Only post')
        ->where('sections.3.type', 'blog'));
    expect(AuditLog::where('action', 'home.updated')->exists())->toBeTrue();
});

test('TC-F16-04 a slider needs two to eight image slides and keeps their order', function () {
    $a = img();
    $b = img();
    $slide = fn (Media $m, string $t) => ['media_id' => $m->id, 'title' => $t, 'text' => '', 'button_label' => '', 'button_url' => ''];
    $admin = adminUser();

    $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('slider', ['autoplay' => true, 'slides' => [$slide($a, 'One')]])))
        ->assertSessionHasErrors('sections.0.slides');

    $this->put('/admin/home-page', layoutWith(sec('slider', ['autoplay' => false, 'slides' => [$slide($b, 'Two'), $slide($a, 'One')]])))->assertSessionHasNoErrors();
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.type', 'slider')->where('sections.0.autoplay', false)->where('sections.0.slides.0.title', 'Two')->where('sections.0.slides.1.title', 'One'));
});

test('TC-F16-05 links must be http(s) or a site path, never script URLs', function () {
    $m = img();
    $admin = adminUser();
    foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test', 'ftp://x.test/a'] as $bad) {
        $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('cta', ['title' => 'T', 'button_label' => 'B', 'button_url' => $bad])))->assertSessionHasErrors('sections.0.button_url');
        $this->put('/admin/home-page', layoutWith(sec('image', ['media_id' => $m->id, 'align' => 'left', 'button_label' => 'B', 'button_url' => $bad])))->assertSessionHasErrors('sections.0.button_url');
    }
    expect(Setting::get('home_sections'))->toBeNull();
});

test('TC-F16-06 an image section must point at an image, not a PDF or a missing file', function () {
    $pdf = img('application/pdf');
    $admin = adminUser();
    foreach ([$pdf->id, 99999] as $id) {
        $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('image', ['media_id' => $id, 'align' => 'left'])))->assertSessionHasErrors('sections.0.media_id');
    }
});

test('TC-F16-07 rich text is sanitised before it is stored', function () {
    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(sec('rich_text', ['title' => 'Hi', 'body' => '<p onclick="x()">Hello <b>there</b></p><script>alert(1)</script><iframe src="https://evil.test"></iframe>'])))->assertSessionHasNoErrors();

    $stored = collect(Setting::get('home_sections'))->firstWhere('type', 'rich_text')['body'];
    expect($stored)->toContain('Hello')->not->toContain('script')->not->toContain('onclick')->not->toContain('iframe');
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.type', 'rich_text')->where('sections.0.title', 'Hi'));

    $this->put('/admin/home-page', layoutWith(sec('rich_text', ['body' => '<p></p>'])))->assertSessionHasErrors('sections.0.body');
});

test('TC-F16-08 the layout needs exactly one blog section and unique ids', function () {
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/home-page', ['sections' => [sec('featured')]])->assertSessionHasErrors('sections');
    $this->put('/admin/home-page', ['sections' => [sec('blog'), sec('blog', ['title' => 'again'])]])->assertSessionHasErrors('sections');
    $dup = sec('featured');
    $this->put('/admin/home-page', ['sections' => [$dup, $dup, sec('blog')]])->assertSessionHasErrors('sections');
    $this->put('/admin/home-page', ['sections' => [['id' => 'x1y2', 'type' => 'script', 'enabled' => true]]])->assertSessionHasErrors('sections.0.type');
    $this->put('/admin/home-page', ['sections' => array_fill(0, 13, sec('cta'))])->assertSessionHasErrors('sections');
    expect(Setting::get('home_sections'))->toBeNull();
});

test('TC-F16-09 unknown keys in a section are dropped, not stored', function () {
    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(sec('cta', ['title' => 'T', 'button_label' => 'B', 'button_url' => '/x', 'html' => '<script>x</script>', 'onclick' => 'x'])));
    $cta = collect(Setting::get('home_sections'))->firstWhere('type', 'cta');
    expect($cta)->not->toHaveKeys(['html', 'onclick']);
});

test('TC-F16-10 a post grid can follow a category or a tag and is limited to published posts', function () {
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);
    homeLive(['category_id' => $cat->id, 'title' => 'In news']);
    homeLive(['title' => 'Elsewhere']);
    Post::factory()->create(['category_id' => $cat->id, 'title' => 'Draft in news']);
    homeLive(['title' => 'Tagged'])->first()->tags()->attach($tag);

    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(
        sec('post_grid', ['title' => 'News', 'source' => 'category', 'category_id' => $cat->id, 'count' => 3]),
        sec('post_grid', ['title' => 'Tagged', 'source' => 'tag', 'tag_id' => $tag->id, 'count' => 3]),
    ))->assertSessionHasNoErrors();

    $this->get('/')->assertInertia(fn ($p) => $p
        ->has('sections.0.posts', 1)->where('sections.0.posts.0.title', 'In news')
        ->has('sections.1.posts', 1)->where('sections.1.posts.0.title', 'Tagged'));

    $this->put('/admin/home-page', layoutWith(sec('post_grid', ['title' => 'x', 'source' => 'category', 'category_id' => null, 'count' => 3])))->assertSessionHasErrors('sections.0.category_id');
});

test('TC-F16-11 a pinned post is the feature and is not repeated in the blog list', function () {
    homeLive([], 8);
    $old = Post::factory()->status('published')->create(['title' => 'Pinned old', 'published_at' => now()->subYear()]);
    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(sec('featured', ['post_id' => $old->id])))->assertSessionHasNoErrors();

    $r = $this->get('/');
    $r->assertInertia(fn ($p) => $p->where('sections.0.featured.title', 'Pinned old')->has('sections.0.latest', 4));
    $titles = collect($r->viewData('page')['props']['sections'])->pluck('posts')->flatten(1)->pluck('title');
    expect($titles)->not->toContain('Pinned old');

    $old->update(['status' => 'draft']); // an unpublished pin falls back to the newest post
    $this->get('/')->assertInertia(fn ($p) => $p->where('sections.0.featured.title', fn ($t) => $t !== 'Pinned old'));
});

test('TC-F16-12 disabled sections are not sent to visitors, and page two shows only the list', function () {
    $this->actingAs(adminUser())->put('/admin/home-page', ['sections' => [sec('cta', ['title' => 'Hidden', 'button_label' => 'B', 'button_url' => '/x', 'enabled' => false]), sec('cta', ['title' => 'Shown', 'button_label' => 'B', 'button_url' => '/x']), sec('blog')]]);
    homeLive([], 14);

    $this->get('/')->assertInertia(fn ($p) => $p->has('sections', 2)->where('sections.0.title', 'Shown'));
    $this->get('/?page=2')->assertInertia(fn ($p) => $p->has('sections', 1)->where('sections.0.type', 'blog'));
});

test('TC-F16-13 an image used on the home page cannot be deleted from the library', function () {
    $m = img();
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('image', ['media_id' => $m->id, 'align' => 'left'])));
    expect($m->isInUse())->toBeTrue();
});

test('TC-F16-14 reset returns to the shipped layout', function () {
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('cta', ['title' => 'T', 'button_label' => 'B', 'button_url' => '/x'])));
    $this->post('/admin/home-page/reset')->assertRedirect();
    expect(array_column(HomePage::layout(), 'type'))->toBe(['featured', 'blog']);
});

test('TC-F16-15 the editor page lists the saved sections with image thumbnails', function () {
    $m = img();
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/home-page', layoutWith(sec('image', ['media_id' => $m->id, 'align' => 'left'])));
    $this->get('/admin/home-page')->assertOk()->assertInertia(fn ($p) => $p->component('admin/home-page')->where('sections.0.thumb', $m->url('thumb')));
});

test('TC-F16-16 home page queries stay constant as sections and posts grow', function () {
    homeLive([], 12);
    $m = img();
    $this->actingAs(adminUser())->put('/admin/home-page', layoutWith(sec('image', ['media_id' => $m->id, 'align' => 'left']), sec('featured')));
    $small = homeQueries('/');
    homeLive([], 40);
    expect(homeQueries('/'))->toBe($small);
});
