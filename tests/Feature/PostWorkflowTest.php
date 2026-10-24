<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Revision;
use App\Models\Tag;
use App\Models\User;

function payload(array $overrides = []): array
{
    return array_merge([
        'action' => 'save', 'title' => 'My first post', 'body' => '<p>Hello world</p>',
        'category_id' => Category::where('is_system', true)->value('id'),
    ], $overrides);
}

test('TC-F06-01 writer drafts, submits, admin publishes, public scope sees it', function () {
    $writer = User::factory()->create();
    $admin = adminUser();

    $this->actingAs($writer)->post('/admin/posts', payload())->assertSessionHasNoErrors();
    $post = Post::firstOrFail();
    expect($post->status)->toBe('draft')->and($post->author_id)->toBe($writer->id)->and($post->slug)->toBe('my-first-post');
    expect(Post::published()->count())->toBe(0);

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['action' => 'submit', 'version' => $post->version]))->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe('pending_review');

    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['action' => 'publish', 'version' => $post->fresh()->version]))->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe('published')->and($post->fresh()->published_at)->not->toBeNull();
    expect(Post::published()->count())->toBe(1);
    expect(AuditLog::whereIn('action', ['post.submitted', 'post.published'])->count())->toBe(2);
});

test('TC-F06-02 writers cannot publish, schedule or edit while in review', function () {
    $writer = User::factory()->create();
    $post = Post::factory()->by($writer)->status('draft')->create();

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['action' => 'publish', 'version' => 1]))->assertForbidden();
    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['action' => 'schedule', 'version' => 1, 'published_at' => now()->addDay()->toIso8601String()]))->assertForbidden();

    $post->update(['status' => 'pending_review']);
    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 1]))->assertForbidden();
    $this->actingAs($writer)->delete("/admin/posts/{$post->id}")->assertForbidden();
    expect($post->fresh()->status)->toBe('pending_review');
});

test('TC-F06-03 writers get a 404 for posts that are not theirs', function () {
    $writer = User::factory()->create();
    $other = Post::factory()->create();

    $this->actingAs($writer)->get("/admin/posts/{$other->id}")->assertNotFound();
    $this->actingAs($writer)->put("/admin/posts/{$other->id}", payload(['version' => 1]))->assertNotFound();
    $this->actingAs($writer)->delete("/admin/posts/{$other->id}")->assertNotFound();
    $this->actingAs($writer)->get("/admin/posts/{$other->id}/revisions")->assertNotFound();
    $this->actingAs($writer)->get('/admin/posts')->assertInertia(fn ($p) => $p->has('posts.data', 0));
});

test('TC-F06-04 admin rejects with a note and the writer can revise and resubmit', function () {
    $writer = User::factory()->create();
    $post = Post::factory()->by($writer)->status('pending_review')->create();

    $this->actingAs(adminUser())->post("/admin/posts/{$post->id}/reject", [])->assertSessionHasErrors('review_note');
    $this->actingAs(adminUser())->post("/admin/posts/{$post->id}/reject", ['review_note' => 'Needs sources'])->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe('rejected')->and($post->fresh()->review_note)->toBe('Needs sources');

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 1, 'title' => 'Revised']))->assertSessionHasNoErrors();
    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 2, 'action' => 'submit']))->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe('pending_review')->and($post->fresh()->review_note)->toBeNull();
});

test('TC-F06-05 scheduled posts publish themselves once due', function () {
    $post = Post::factory()->status('pending_review')->create();

    $this->actingAs(adminUser())->put("/admin/posts/{$post->id}", payload(['action' => 'schedule', 'version' => 1, 'published_at' => now()->addHour()->toIso8601String()]))
        ->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe('scheduled');

    $this->artisan('cms:publish-scheduled')->assertSuccessful();
    expect($post->fresh()->status)->toBe('scheduled')->and(Post::published()->count())->toBe(0);

    $this->travel(61)->minutes();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();
    expect($post->fresh()->status)->toBe('published')->and(Post::published()->count())->toBe(1);
});

test('scheduling needs a future date and publishing refuses one', function () {
    $post = Post::factory()->status('pending_review')->create();
    $admin = adminUser();

    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['action' => 'schedule', 'version' => 1, 'published_at' => now()->subDay()->toIso8601String()]))->assertSessionHasErrors('published_at');
    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['action' => 'schedule', 'version' => 1]))->assertSessionHasErrors('published_at');
    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['action' => 'publish', 'version' => 1, 'published_at' => now()->addDay()->toIso8601String()]))->assertSessionHasErrors('published_at');
    expect($post->fresh()->status)->toBe('pending_review');
});

test('TC-F06-06 changing the slug of a live post leaves a 301 redirect, and chains collapse', function () {
    $admin = adminUser();
    $post = Post::factory()->status('published')->create(['slug' => 'old-slug']);

    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['version' => 1, 'slug' => 'middle-slug']))->assertSessionHasNoErrors();
    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['version' => 2, 'slug' => 'final-slug']))->assertSessionHasNoErrors();

    expect(Redirect::where('from_path', '/blog/old-slug')->value('to_path'))->toBe('/blog/final-slug');
    expect(Redirect::where('from_path', '/blog/middle-slug')->value('to_path'))->toBe('/blog/final-slug');
});

test('slug changes on drafts leave no redirect', function () {
    $writer = User::factory()->create();
    $post = Post::factory()->by($writer)->create(['slug' => 'draft-slug']);

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 1, 'slug' => 'new-draft-slug']));

    expect(Redirect::count())->toBe(0);
});

test('TC-F06-07 revisions are kept and can be restored', function () {
    $admin = adminUser();
    $this->actingAs($admin)->post('/admin/posts', payload(['title' => 'v1', 'body' => '<p>one</p>']));
    $post = Post::firstOrFail();
    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['title' => 'v2', 'body' => '<p>two</p>', 'version' => 1]));
    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['title' => 'v3', 'body' => '<p>three</p>', 'version' => 2]));
    expect($post->revisions()->count())->toBe(3);

    $first = $post->revisions()->orderBy('id')->first();
    $this->actingAs($admin)->get("/admin/posts/{$post->id}/revisions")->assertOk()->assertJsonCount(3)->assertJsonPath('0.title', 'v3');
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/revisions/{$first->id}/restore")->assertSessionHasNoErrors();

    expect($post->fresh()->title)->toBe('v1')->and($post->fresh()->body)->toBe('<p>one</p>')->and($post->revisions()->count())->toBe(4);
});

test('saving without content changes does not create a revision', function () {
    $admin = adminUser();
    $this->actingAs($admin)->post('/admin/posts', payload());
    $post = Post::firstOrFail();

    $this->actingAs($admin)->put("/admin/posts/{$post->id}", payload(['version' => 1, 'excerpt' => 'only excerpt changed']));

    expect($post->revisions()->count())->toBe(1);
});

test('TC-F06-08 stale versions are rejected on save and autosave', function () {
    $writer = User::factory()->create();
    $post = Post::factory()->by($writer)->create();

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 1]))->assertSessionHasNoErrors();

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['version' => 1, 'title' => 'Stale']))->assertSessionHasErrors('version');
    $this->actingAs($writer)->putJson("/admin/posts/{$post->id}/autosave", ['title' => 'Stale', 'body' => '<p>x</p>', 'version' => 1])
        ->assertStatus(409)->assertJsonPath('version', 2);
    expect($post->fresh()->title)->not->toBe('Stale');
});

test('autosave updates a draft without creating a revision and bumps the version', function () {
    $writer = User::factory()->create();
    $post = Post::factory()->by($writer)->create();

    $this->actingAs($writer)->putJson("/admin/posts/{$post->id}/autosave", ['title' => 'Auto', 'body' => '<p>auto</p><script>x</script>', 'version' => 1])
        ->assertOk()->assertJsonPath('version', 2);

    expect($post->fresh()->title)->toBe('Auto')->and($post->fresh()->body)->toBe('<p>auto</p>');
    expect(Revision::count())->toBe(0);

    $post->update(['status' => 'published']);
    $this->actingAs(adminUser())->putJson("/admin/posts/{$post->id}/autosave", ['title' => 'x', 'version' => 3])->assertStatus(422);
});

test('TC-F06-09 submitting needs a body and category', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/admin/posts', payload(['action' => 'submit', 'body' => '']))->assertSessionHasErrors('body');
    $this->actingAs($writer)->post('/admin/posts', payload(['action' => 'submit', 'body' => '<p></p>']))->assertSessionHasErrors('body');
    $this->actingAs($writer)->post('/admin/posts', payload(['action' => 'submit', 'category_id' => null]))->assertSessionHasErrors('category_id');
    $this->actingAs($writer)->post('/admin/posts', payload(['action' => 'submit', 'title' => '']))->assertSessionHasErrors('title');
    expect(Post::count())->toBe(0);
});

test('a draft can be saved without a category and falls back to Uncategorized', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/admin/posts', payload(['category_id' => null]))->assertSessionHasNoErrors();

    expect(Post::firstOrFail()->category->slug)->toBe('uncategorized');
});

test('TC-F06-10 only the latest 50 revisions are kept', function () {
    $admin = adminUser();
    $post = Post::factory()->create();

    foreach (range(1, 52) as $i) {
        $post->body = "<p>edit {$i}</p>";
        $post->save();
        $post->captureRevision($admin->id);
    }

    expect($post->revisions()->count())->toBe(50);
    expect($post->revisions()->orderBy('id')->first()->body)->toBe('<p>edit 3</p>');
});

test('body is sanitised on save and the integrity hash tracks the stored content', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/admin/posts', payload(['body' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>']));
    $post = Post::firstOrFail();

    expect($post->body)->toBe('<p>ok</p>');
    expect($post->content_hash)->toBe(hash('sha256', $post->title."\n".$post->body));
});

test('mass assignment cannot set author, status or author on update', function () {
    $writer = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($writer)->post('/admin/posts', payload(['author_id' => $other->id, 'status' => 'published']));

    $post = Post::firstOrFail();
    expect($post->author_id)->toBe($writer->id)->and($post->status)->toBe('draft');
});

test('featured image must be an image the user can use', function () {
    $writer = User::factory()->create();
    $mine = Media::create(['uuid' => (string) Str::uuid(), 'uploader_id' => $writer->id, 'path' => 'uploads/a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'alt_text' => 'a']);
    $theirs = Media::create(['uuid' => (string) Str::uuid(), 'uploader_id' => adminUser()->id, 'path' => 'uploads/b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'alt_text' => 'b']);
    $pdf = Media::create(['uuid' => (string) Str::uuid(), 'uploader_id' => $writer->id, 'path' => 'files/c.pdf', 'original_name' => 'c.pdf', 'mime' => 'application/pdf', 'size' => 1, 'alt_text' => 'c']);

    $this->actingAs($writer)->post('/admin/posts', payload(['featured_media_id' => $theirs->id]))->assertSessionHasErrors('featured_media_id');
    $this->actingAs($writer)->post('/admin/posts', payload(['featured_media_id' => $pdf->id]))->assertSessionHasErrors('featured_media_id');
    $this->actingAs($writer)->post('/admin/posts', payload(['featured_media_id' => $mine->id]))->assertSessionHasNoErrors();
});

test('tags are synced on save', function () {
    $writer = User::factory()->create();
    $a = Tag::create(['name' => 'A', 'slug' => 'a']);
    $b = Tag::create(['name' => 'B', 'slug' => 'b']);

    $this->actingAs($writer)->post('/admin/posts', payload(['tag_ids' => [$a->id, $b->id]]));
    $post = Post::firstOrFail();
    expect($post->tags()->pluck('tags.id')->all())->toEqualCanonicalizing([$a->id, $b->id]);

    $this->actingAs($writer)->put("/admin/posts/{$post->id}", payload(['tag_ids' => [$b->id], 'version' => 1]));
    expect($post->tags()->pluck('tags.id')->all())->toBe([$b->id]);
});

test('trash, restore and purge follow the policy', function () {
    $writer = User::factory()->create();
    $admin = adminUser();
    $post = Post::factory()->by($writer)->create();

    $this->actingAs($writer)->delete("/admin/posts/{$post->id}")->assertSessionHasNoErrors();
    expect($post->fresh()->trashed())->toBeTrue();

    $this->actingAs($writer)->delete("/admin/posts/{$post->id}/purge")->assertForbidden();
    $this->actingAs($writer)->post("/admin/posts/{$post->id}/restore")->assertSessionHasNoErrors();
    expect(Post::find($post->id)->status)->toBe('draft');

    $this->actingAs($admin)->delete("/admin/posts/{$post->id}");
    $this->actingAs($admin)->delete("/admin/posts/{$post->id}/purge");
    expect(Post::withTrashed()->count())->toBe(0)->and(Revision::count())->toBe(0);
});

test('unpublish, archive and restore moves are admin only and obey the state machine', function () {
    $writer = User::factory()->create();
    $admin = adminUser();
    $post = Post::factory()->by($writer)->status('published')->create();

    $this->actingAs($writer)->post("/admin/posts/{$post->id}/archive")->assertForbidden();
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/archive");
    expect($post->fresh()->status)->toBe('archived');
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/archive")->assertForbidden(); // not published any more
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/unarchive");
    expect($post->fresh()->status)->toBe('draft');
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/unpublish")->assertForbidden();
});

test('admins can fast-track their own draft but not someone else\'s', function () {
    $admin = adminUser();
    $own = Post::factory()->by($admin)->create();
    $theirs = Post::factory()->create();

    $this->actingAs($admin)->put("/admin/posts/{$own->id}", payload(['action' => 'publish', 'version' => 1]))->assertSessionHasNoErrors();
    $this->actingAs($admin)->put("/admin/posts/{$theirs->id}", payload(['action' => 'publish', 'version' => 1]))->assertForbidden();

    expect($own->fresh()->status)->toBe('published')->and($theirs->fresh()->status)->toBe('draft');
});

test('post list and editor load in a constant number of queries', function () {
    $admin = adminUser();
    Post::factory()->count(30)->create();
    DB::enableQueryLog();

    $this->actingAs($admin)->get('/admin/posts')->assertOk()->assertInertia(fn ($p) => $p->has('posts.data', 15));
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);

    DB::flushQueryLog();
    $this->actingAs($admin)->get('/admin/posts/'.Post::first()->id)->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(14);
});

test('the post list filters by status, category, author and title', function () {
    $admin = adminUser();
    $writer = User::factory()->create();
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);
    Post::factory()->by($writer)->status('published')->create(['title' => 'Findme alpha', 'category_id' => $cat->id]);
    Post::factory()->status('draft')->create(['title' => 'Other']);

    $this->actingAs($admin)->get('/admin/posts?status=published')->assertInertia(fn ($p) => $p->has('posts.data', 1));
    $this->actingAs($admin)->get("/admin/posts?category={$cat->id}")->assertInertia(fn ($p) => $p->has('posts.data', 1));
    $this->actingAs($admin)->get("/admin/posts?author={$writer->id}")->assertInertia(fn ($p) => $p->has('posts.data', 1));
    $this->actingAs($admin)->get('/admin/posts?q=Findme')->assertInertia(fn ($p) => $p->has('posts.data', 1)->where('counts.published', 1)->where('counts.draft', 1));
});
