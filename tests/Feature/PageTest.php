<?php

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Revision;
use App\Models\User;

function pagePayload(array $overrides = []): array
{
    return array_merge(['action' => 'save', 'title' => 'About us', 'body' => '<p>We are a team.</p>'], $overrides);
}

test('TC-F07-01 writers and guests cannot manage pages', function () {
    $writer = User::factory()->create();
    $page = Page::factory()->create();

    $this->actingAs($writer)->get('/admin/pages')->assertForbidden();
    $this->actingAs($writer)->post('/admin/pages', pagePayload())->assertForbidden();
    $this->actingAs($writer)->put("/admin/pages/{$page->id}", pagePayload(['version' => 1]))->assertForbidden();
    $this->actingAs($writer)->delete("/admin/pages/{$page->id}")->assertForbidden();
    auth()->logout();
    $this->get('/admin/pages')->assertRedirect('/login');
});

test('TC-F07-02 admin creates a parent and child page and publishes', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/pages', pagePayload())->assertSessionHasNoErrors();
    $parent = Page::firstOrFail();
    expect($parent->status)->toBe('draft')->and($parent->slug)->toBe('about-us')->and($parent->updated_by)->toBe($admin->id);

    $this->actingAs($admin)->post('/admin/pages', pagePayload(['title' => 'Team', 'parent_id' => $parent->id, 'sort_order' => 2, 'action' => 'publish']))
        ->assertSessionHasNoErrors();

    $child = Page::where('slug', 'team')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id)->and($child->status)->toBe('published')->and($child->sort_order)->toBe(2);
    expect(Page::published()->count())->toBe(1);
});

test('publishing needs content', function () {
    $this->actingAs(adminUser())->post('/admin/pages', pagePayload(['action' => 'publish', 'body' => '<p> </p>']))->assertSessionHasErrors('body');
});

test('TC-F07-03 a page with children cannot be deleted', function () {
    $admin = adminUser();
    $parent = Page::factory()->create();
    $child = Page::factory()->create(['parent_id' => $parent->id]);

    $this->actingAs($admin)->delete("/admin/pages/{$parent->id}")->assertSessionHasErrors('page');
    expect($parent->fresh()->trashed())->toBeFalse();

    $this->actingAs($admin)->delete("/admin/pages/{$child->id}")->assertSessionHasNoErrors();
    $this->actingAs($admin)->delete("/admin/pages/{$parent->id}")->assertSessionHasNoErrors();
    expect(Page::count())->toBe(0);
});

test('pages nest one level only and never under themselves', function () {
    $admin = adminUser();
    $parent = Page::factory()->create();
    $child = Page::factory()->create(['parent_id' => $parent->id]);

    $this->actingAs($admin)->post('/admin/pages', pagePayload(['parent_id' => $child->id]))->assertSessionHasErrors('parent_id');
    $this->actingAs($admin)->put("/admin/pages/{$parent->id}", pagePayload(['parent_id' => $parent->id, 'version' => 1]))->assertSessionHasErrors('parent_id');
    $this->actingAs($admin)->put("/admin/pages/{$parent->id}", pagePayload(['parent_id' => Page::factory()->create()->id, 'version' => 1]))->assertSessionHasErrors('parent_id'); // has children
});

test('reserved and duplicate slugs are rejected', function () {
    $admin = adminUser();
    Page::factory()->create(['slug' => 'taken']);

    foreach (['admin', 'login', 'blog', 'media', 'sitemap'] as $slug) {
        $this->actingAs($admin)->post('/admin/pages', pagePayload(['slug' => $slug]))->assertSessionHasErrors('slug');
    }
    $this->actingAs($admin)->post('/admin/pages', pagePayload(['slug' => 'taken']))->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post('/admin/pages', pagePayload(['title' => 'Admin']))->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post('/admin/pages', pagePayload(['slug' => 'Bad Slug!']))->assertSessionHasErrors('slug');
    expect(Page::count())->toBe(1);
});

test('non-Latin titles still get a usable slug', function () {
    $this->actingAs(adminUser())->post('/admin/pages', pagePayload(['title' => '日本語のページ']))->assertSessionHasNoErrors();

    expect(Page::firstOrFail()->slug)->toMatch('/^n-[a-z0-9]{6}$/');
});

test('slug change on a published page leaves a redirect; unpublishing returns it to draft', function () {
    $admin = adminUser();
    $page = Page::factory()->create(['slug' => 'old', 'status' => 'published']);

    $this->actingAs($admin)->put("/admin/pages/{$page->id}", pagePayload(['slug' => 'new', 'version' => 1]))->assertSessionHasNoErrors();
    expect(Redirect::where('from_path', '/old')->value('to_path'))->toBe('/new');

    $this->actingAs($admin)->post("/admin/pages/{$page->id}/unpublish");
    expect($page->fresh()->status)->toBe('draft')->and(Page::published()->count())->toBe(0);
});

test('page revisions are recorded and can be restored; versions guard concurrent edits', function () {
    $admin = adminUser();
    $this->actingAs($admin)->post('/admin/pages', pagePayload(['title' => 'v1', 'body' => '<p>one</p>']));
    $page = Page::firstOrFail();
    $this->actingAs($admin)->put("/admin/pages/{$page->id}", pagePayload(['title' => 'v2', 'body' => '<p>two</p>', 'version' => 1]));
    expect($page->revisions()->count())->toBe(2);

    $this->actingAs($admin)->put("/admin/pages/{$page->id}", pagePayload(['title' => 'stale', 'version' => 1]))->assertSessionHasErrors('version');

    $first = $page->revisions()->orderBy('id')->first();
    $this->actingAs($admin)->get("/admin/pages/{$page->id}/revisions")->assertOk()->assertJsonCount(2);
    $this->actingAs($admin)->post("/admin/pages/{$page->id}/revisions/{$first->id}/restore");
    expect($page->fresh()->title)->toBe('v1');
});

test('page body is sanitised and hashed', function () {
    $this->actingAs(adminUser())->post('/admin/pages', pagePayload(['body' => '<p>ok</p><iframe src="https://evil.example"></iframe>']));

    $page = Page::firstOrFail();
    expect($page->body)->toBe('<p>ok</p>')->and($page->content_hash)->toBe(hash('sha256', $page->title."\n".$page->body));
});

test('trashed pages can be restored as drafts or purged with their revisions', function () {
    $admin = adminUser();
    $page = Page::factory()->create(['status' => 'published']);
    $page->captureRevision($admin->id);

    $this->actingAs($admin)->delete("/admin/pages/{$page->id}");
    $this->actingAs($admin)->post("/admin/pages/{$page->id}/restore");
    expect(Page::find($page->id)->status)->toBe('draft');

    $this->actingAs($admin)->delete("/admin/pages/{$page->id}");
    $this->actingAs($admin)->delete("/admin/pages/{$page->id}/purge");
    expect(Page::withTrashed()->count())->toBe(0)->and(Revision::count())->toBe(0);
});

test('page list is a constant number of queries', function () {
    $admin = adminUser();
    $parent = Page::factory()->create();
    Page::factory()->count(20)->create(['parent_id' => $parent->id]);

    DB::enableQueryLog();
    $this->actingAs($admin)->get('/admin/pages')->assertOk()->assertInertia(fn ($p) => $p->has('pages.data', 20));
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(10);
});
