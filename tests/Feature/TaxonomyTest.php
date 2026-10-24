<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

test('TC-F04-01 admin creates a category and tag with unique slugs', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/categories', ['name' => 'News'])->assertSessionHasNoErrors();
    expect(Category::where('slug', 'news')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/admin/tags', ['name' => 'Laravel'])->assertSessionHasNoErrors();
    expect(Tag::where('slug', 'laravel')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'category.created')->exists())->toBeTrue();
});

test('slugs stay unique for names that collide after slugging and for non-Latin names', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/tags', ['name' => 'C++']);
    $this->actingAs($admin)->post('/admin/tags', ['name' => 'C#']);
    $this->actingAs($admin)->post('/admin/tags', ['name' => '日本語']);

    $slugs = Tag::pluck('slug');
    expect($slugs)->toHaveCount(3)->and($slugs->unique())->toHaveCount(3);
    expect($slugs->every(fn ($s) => $s !== ''))->toBeTrue();
});

test('TC-F04-03 tag names are unique regardless of case', function () {
    $admin = adminUser();
    Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->actingAs($admin)->post('/admin/tags', ['name' => 'laravel'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/admin/categories', ['name' => 'Uncategorized'])->assertSessionHasErrors('name');
});

test('TC-F04-04 writers create tags but cannot manage taxonomy', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->postJson('/tags/find-or-create', ['name' => 'Fresh'])->assertCreated()->assertJsonPath('name', 'Fresh');
    $tag = Tag::firstWhere('name', 'Fresh');

    $this->actingAs($writer)->delete("/admin/tags/{$tag->id}")->assertForbidden();
    $this->actingAs($writer)->post('/admin/tags', ['name' => 'X'])->assertForbidden();
    $this->actingAs($writer)->post('/admin/categories', ['name' => 'X'])->assertForbidden();
    $this->actingAs($writer)->get('/admin/categories')->assertForbidden();
});

test('find-or-create returns the existing tag instead of duplicating', function () {
    $writer = User::factory()->create();
    Tag::create(['name' => 'PHP', 'slug' => 'php']);

    $this->actingAs($writer)->postJson('/tags/find-or-create', ['name' => 'php'])->assertOk()->assertJsonPath('slug', 'php');
    expect(Tag::count())->toBe(1);
});

test('categories nest one level only', function () {
    $admin = adminUser();
    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $child = Category::create(['name' => 'PHP', 'slug' => 'php', 'parent_id' => $parent->id]);

    $this->actingAs($admin)->post('/admin/categories', ['name' => 'Deep', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
    $this->actingAs($admin)->put("/admin/categories/{$parent->id}", ['name' => 'Tech', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
    $this->actingAs($admin)->put("/admin/categories/{$parent->id}", ['name' => 'Tech', 'parent_id' => $parent->id])->assertSessionHasErrors('parent_id');
});

test('the system category cannot be edited or deleted; categories with children cannot be deleted', function () {
    $admin = adminUser();
    $system = Category::firstWhere('slug', 'uncategorized');
    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    Category::create(['name' => 'PHP', 'slug' => 'php', 'parent_id' => $parent->id]);

    $this->actingAs($admin)->delete("/admin/categories/{$system->id}")->assertStatus(422);
    $this->actingAs($admin)->put("/admin/categories/{$system->id}", ['name' => 'Renamed'])->assertStatus(422);
    $this->actingAs($admin)->delete("/admin/categories/{$parent->id}")->assertSessionHasErrors('category');

    expect(Category::count())->toBe(3);
});

test('category pages load with a constant number of queries', function () {
    $admin = adminUser();
    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    foreach (range(1, 15) as $i) {
        Category::create(['name' => "Cat {$i}", 'slug' => "cat-{$i}", 'parent_id' => $parent->id]);
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->get('/admin/categories')->assertOk()->assertInertia(fn ($p) => $p->has('categories', 17));
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});

test('admin can rename and delete tags and categories', function () {
    $admin = adminUser();
    $tag = Tag::create(['name' => 'Old', 'slug' => 'old']);
    $cat = Category::create(['name' => 'Temp', 'slug' => 'temp']);

    $this->actingAs($admin)->put("/admin/tags/{$tag->id}", ['name' => 'New'])->assertSessionHasNoErrors();
    expect($tag->fresh()->name)->toBe('New')->and($tag->fresh()->slug)->toBe('old');

    $this->actingAs($admin)->delete("/admin/tags/{$tag->id}");
    $this->actingAs($admin)->delete("/admin/categories/{$cat->id}");
    expect(Tag::count())->toBe(0)->and(Category::where('slug', 'temp')->exists())->toBeFalse();
});

test('TC-F04-02 deleting a category moves its posts (even trashed ones) to Uncategorized', function () {
    $admin = adminUser();
    $news = Category::create(['name' => 'News', 'slug' => 'news']);
    $live = Post::factory()->create(['category_id' => $news->id]);
    $trashed = Post::factory()->create(['category_id' => $news->id]);
    $trashed->delete();

    $this->actingAs($admin)->delete("/admin/categories/{$news->id}")->assertSessionHasNoErrors();

    $fallback = Category::firstWhere('slug', 'uncategorized')->id;
    expect(Category::find($news->id))->toBeNull();
    expect($live->fresh()->category_id)->toBe($fallback)->and(Post::withTrashed()->find($trashed->id)->category_id)->toBe($fallback);
});

test('TC-F04-05 merging tags moves posts without duplicates and removes the source', function () {
    $admin = adminUser();
    $source = Tag::create(['name' => 'Laravl', 'slug' => 'laravl']);
    $target = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);
    $both = Post::factory()->create();
    $only = Post::factory()->create();
    $both->tags()->attach([$source->id, $target->id]);
    $only->tags()->attach($source->id);

    $this->actingAs($admin)->post('/admin/tags/merge', ['source_id' => $source->id, 'target_id' => $target->id])->assertSessionHasNoErrors();

    expect(Tag::find($source->id))->toBeNull();
    expect($target->posts()->pluck('posts.id')->all())->toEqualCanonicalizing([$both->id, $only->id]);
    expect(DB::table('post_tag')->where('post_id', $both->id)->count())->toBe(1);
    $this->actingAs($admin)->post('/admin/tags/merge', ['source_id' => $target->id, 'target_id' => $target->id])->assertSessionHasErrors('target_id');
    $this->actingAs(User::factory()->create())->post('/admin/tags/merge', ['source_id' => 1, 'target_id' => 2])->assertForbidden();
});

test('FR-23 category and tag lists carry published-post counters without extra queries', function () {
    $admin = adminUser();
    $cat = Category::create(['name' => 'News', 'slug' => 'news']);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);
    foreach (['published', 'published', 'draft'] as $status) {
        Post::factory()->status($status)->create(['category_id' => $cat->id])->tags()->attach($tag->id);
    }

    $this->actingAs($admin)->get('/admin/categories')->assertInertia(fn ($p) => $p->where('categories', fn ($rows) => collect($rows)->firstWhere('slug', 'news')['posts_count'] === 2));
    $this->actingAs($admin)->get('/admin/tags')->assertInertia(fn ($p) => $p->where('tags.data.0.posts_count', 2));
});
