<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function settingsPayload(array $o = []): array
{
    return [...[
        'site_name' => 'Essos', 'tagline' => 'Stories', 'logo_media_id' => null, 'favicon_media_id' => null,
        'content_language' => 'en', 'posts_per_page' => 6, 'cta_label' => '', 'cta_url' => '', 'pdf_max_mb' => 10,
        'mail_from_name' => '', 'mail_from_address' => '', 'maintenance_mode' => false,
        'analytics_enabled' => true, 'analytics_retention_days' => 90,
    ], ...$o];
}

test('TC-F10-01 admin changes site identity and the public site follows', function () {
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/settings')->assertOk();
    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['site_name' => 'Essos', 'tagline' => 'Stories that last', 'cta_label' => 'Get started', 'cta_url' => '/contact']))->assertSessionHasNoErrors();
    auth()->logout();

    $this->get('/')->assertInertia(fn ($p) => $p->where('site.name', 'Essos')->where('site.tagline', 'Stories that last')->where('site.cta.label', 'Get started')->where('seo.title', 'Essos — Stories that last'));
    expect(AuditLog::where('action', 'settings.updated')->exists())->toBeTrue();
});

test('settings are admin only', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->get('/admin/settings')->assertForbidden();
    $this->actingAs($writer)->put('/admin/settings', settingsPayload())->assertForbidden();
    $this->actingAs($writer)->get('/admin/menus')->assertForbidden();
    $this->actingAs($writer)->put('/admin/menus/header', ['items' => []])->assertForbidden();
});

test('settings are validated', function () {
    $admin = adminUser();

    foreach ([
        ['posts_per_page' => 4], ['posts_per_page' => 51], ['pdf_max_mb' => 0], ['pdf_max_mb' => 21],
        ['content_language' => 'English!'], ['cta_url' => 'javascript:alert(1)', 'cta_label' => 'x'], ['cta_label' => 'Only label'],
        ['mail_from_address' => 'not-an-email'], ['logo_media_id' => 999], ['site_name' => str_repeat('a', 61)],
    ] as $bad) {
        $this->actingAs($admin)->put('/admin/settings', settingsPayload($bad))->assertSessionHasErrors();
    }
    expect(Setting::count())->toBe(0);
});

test('an empty site name falls back to the application name and posts per page drives pagination', function () {
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['site_name' => '', 'posts_per_page' => 5]));
    Post::factory()->count(12)->status('published')->create();
    auth()->logout();

    $this->get('/blog')->assertInertia(fn ($p) => $p->where('site.name', config('app.name'))->has('posts', 5)->where('pager.last', 3));
});

test('the logo and favicon come from the media library and cannot be deleted while in use', function () {
    Storage::fake('public');
    $admin = adminUser();
    $this->actingAs($admin)->post('/media', ['file' => UploadedFile::fake()->image('logo.png', 200, 80), 'alt_text' => 'Logo']);
    $logo = Media::firstOrFail();

    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['logo_media_id' => $logo->id, 'favicon_media_id' => $logo->id]))->assertSessionHasNoErrors();
    auth()->logout();
    $this->get('/')->assertInertia(fn ($p) => $p->where('site.logo.alt', 'Logo')->where('site.favicon', fn ($v) => str_starts_with($v, '/storage/uploads/')));

    $this->actingAs($admin)->delete("/media/{$logo->id}")->assertSessionHasErrors('media');
});

test('the PDF size limit setting is enforced on upload', function () {
    Storage::fake('local');
    $admin = adminUser();
    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['pdf_max_mb' => 1]));
    $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('a', 1100 * 1024));

    $this->actingAs($admin)->post('/media', ['file' => $big, 'alt_text' => 'Big'])->assertSessionHasErrors('file');

    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['pdf_max_mb' => 2]));
    $this->actingAs($admin)->post('/media', ['file' => $big, 'alt_text' => 'Big'])->assertSessionHasNoErrors();
});

test('TC-F10-03 maintenance mode shows a 503 to visitors and writers but not to signed-in admins', function () {
    $admin = adminUser();
    $writer = User::factory()->create();
    Post::factory()->status('published')->create(['slug' => 'live']);

    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['maintenance_mode' => true]))->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'maintenance.enabled')->exists())->toBeTrue();
    auth()->logout();

    $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '3600')->assertInertia(fn ($p) => $p->component('error')->where('message', 'maintenance'));
    $this->get('/blog/live')->assertStatus(503);
    $this->get('/sitemap.xml')->assertStatus(503);
    $this->get('/login')->assertOk();
    $this->actingAs($writer)->get('/dashboard')->assertStatus(503);

    $this->actingAs($admin)->get('/')->assertOk();
    $this->actingAs($admin)->get('/admin/settings')->assertOk();

    $this->actingAs($admin)->put('/admin/settings', settingsPayload(['maintenance_mode' => false]));
    auth()->logout();
    $this->get('/')->assertOk();
});

test('TC-F10-01 menus render in order and skip unpublished pages', function () {
    $admin = adminUser();
    $about = Page::factory()->create(['title' => 'About', 'slug' => 'about', 'status' => 'published']);
    $draft = Page::factory()->create(['title' => 'Soon', 'slug' => 'soon', 'status' => 'draft']);
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);

    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [
        ['label' => 'Blog', 'type' => 'url', 'url' => '/blog'],
        ['label' => 'About', 'type' => 'page', 'page_id' => $about->id],
        ['label' => 'Coming', 'type' => 'page', 'page_id' => $draft->id],
        ['label' => 'News', 'type' => 'category', 'category_id' => $cat->id],
    ]])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/admin/menus/footer', ['items' => [['label' => 'Docs', 'type' => 'url', 'url' => 'https://example.com/docs']]])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get('/')->assertInertia(fn ($p) => $p->where('site.menu.header.0.href', '/blog')->where('site.menu.header.1.href', '/about')
        ->where('site.menu.header.2.href', '/category/news')->has('site.menu.header', 3)
        ->where('site.menu.footer.0.external', true));
});

test('TC-F10-02 menu links must be http(s) or a site path', function () {
    $admin = adminUser();

    foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', 'mailto:a@b.c', '//evil.example', 'no-scheme'] as $bad) {
        $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => 'x', 'type' => 'url', 'url' => $bad]]])->assertSessionHasErrors('items.0.url');
    }
    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => 'x', 'type' => 'page', 'page_id' => 999]]])->assertSessionHasErrors('items.0.page_id');
    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => '', 'type' => 'url', 'url' => '/x']]])->assertSessionHasErrors('items.0.label');
    $this->actingAs($admin)->put('/admin/menus/sidebar', ['items' => []])->assertNotFound();
    $this->actingAs($admin)->put('/admin/menus/header', ['items' => array_fill(0, 13, ['label' => 'x', 'type' => 'url', 'url' => '/x'])])->assertSessionHasErrors('items');
    expect(MenuItem::count())->toBe(0);
});

test('saving a menu replaces it, and removing a page removes it from the menu', function () {
    $admin = adminUser();
    $page = Page::factory()->create(['slug' => 'about', 'status' => 'published']);
    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => 'About', 'type' => 'page', 'page_id' => $page->id], ['label' => 'Blog', 'type' => 'url', 'url' => '/blog']]]);
    expect(MenuItem::count())->toBe(2);

    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => 'Blog', 'type' => 'url', 'url' => '/blog']]]);
    expect(MenuItem::count())->toBe(1);

    $this->actingAs($admin)->put('/admin/menus/header', ['items' => [['label' => 'About', 'type' => 'page', 'page_id' => $page->id]]]);
    $page->forceDelete();
    expect(MenuItem::count())->toBe(0);
});

test('the dashboard shows role-scoped counts', function () {
    $writer = User::factory()->create();
    Post::factory()->by($writer)->create();
    Post::factory()->create();

    $this->actingAs($writer)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('dashboard')->where('counts.draft', 1));
    $this->actingAs(adminUser())->get('/dashboard')->assertInertia(fn ($p) => $p->where('counts.draft', 2));
});
