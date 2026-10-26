<?php

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/** A real temp file: Laravel's fake files guess the MIME from the extension, which would hide the finfo check. */
function realUpload(string $content, string $name): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);

    return new UploadedFile($tmp, $name, null, null, true);
}

function pdf(int $kb = 10, string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('a', $kb * 1024)."\n%%EOF");
}

test('TC-F05-02 a valid image is re-encoded as WebP under a UUID name with variants', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/media', [
        'file' => UploadedFile::fake()->image('Holiday Photo.PNG', 800, 600),
        'alt_text' => 'A holiday photo',
    ])->assertSessionHasNoErrors();

    $m = Media::firstOrFail();
    expect($m->mime)->toBe('image/webp')->and($m->width)->toBe(800)->and($m->height)->toBe(600)
        ->and($m->path)->toMatch('#^uploads/\d{4}/\d{2}/[0-9a-f-]{36}\.webp$#')
        ->and($m->uploader_id)->toBe($writer->id);
    foreach ($m->allPaths() as $path) {
        Storage::disk('public')->assertExists($path);
    }
    expect(count($m->allPaths()))->toBe(4);
});

test('EXIF and trailing payload do not survive re-encoding', function () {
    $writer = User::factory()->create();
    $jpeg = UploadedFile::fake()->image('p.jpg', 100, 100)->get();
    $file = realUpload($jpeg.'<?php system($_GET["c"]); ?>MARKER', 'p.jpg');

    $this->actingAs($writer)->post('/media', ['file' => $file, 'alt_text' => 'x'])->assertSessionHasNoErrors();

    $stored = Storage::disk('public')->get(Media::firstOrFail()->path);
    expect($stored)->not->toContain('MARKER')->not->toContain('<?php');
});

test('TC-F05-01 a PHP file renamed to .jpg or .pdf is rejected', function () {
    $writer = User::factory()->create();

    foreach (['evil.jpg', 'evil.pdf', 'evil.php'] as $name) {
        $file = realUpload('<?php echo 1;', $name);
        $this->actingAs($writer)->post('/media', ['file' => $file, 'alt_text' => 'x'])->assertSessionHasErrors('file');
    }
    expect(Media::count())->toBe(0);
});

test('TC-F05-03 oversized files, SVG and missing alt text are rejected', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('big.jpg')->size(5 * 1024 + 1), 'alt_text' => 'x'])
        ->assertSessionHasErrors('file');
    $this->actingAs($writer)->post('/media', ['file' => pdf(10 * 1024 + 1), 'alt_text' => 'x'])->assertSessionHasErrors('file');

    $svg = realUpload('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'a.svg');
    $this->actingAs($writer)->post('/media', ['file' => $svg, 'alt_text' => 'x'])->assertSessionHasErrors('file');

    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasErrors('alt_text');
    expect(Media::count())->toBe(0);
});

test('TC-F05-06 PDFs are stored privately and served as sandboxed attachments', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/media', ['file' => pdf(), 'alt_text' => 'Annual report'])->assertSessionHasNoErrors();

    $m = Media::firstOrFail();
    expect($m->path)->toMatch('#^files/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#');
    Storage::disk('local')->assertExists($m->path);
    Storage::disk('public')->assertMissing($m->path);

    auth()->logout();
    $this->get("/files/{$m->uuid}")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
        ->assertHeader('Content-Disposition', 'attachment; filename=doc.pdf');
});

test('TC-F05-07 a non-PDF named .pdf and an unknown uuid are refused', function () {
    $writer = User::factory()->create();
    $fake = realUpload('just text', 'a.pdf');

    $this->actingAs($writer)->post('/media', ['file' => $fake, 'alt_text' => 'x'])->assertSessionHasErrors('file');
    $this->get('/files/00000000-0000-4000-8000-000000000000')->assertNotFound();
});

test('image files are never served through the PDF route', function () {
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'x']);

    $this->get('/files/'.Media::firstOrFail()->uuid)->assertNotFound();
});

test('TC-F05-04 writers only see and manage their own media', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $admin = adminUser();
    $this->actingAs($a)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'a']);
    $mine = Media::firstOrFail();

    $this->actingAs($b)->get('/media')->assertOk()->assertInertia(fn ($p) => $p->has('items.data', 0));
    $this->actingAs($b)->put("/media/{$mine->id}", ['alt_text' => 'hacked'])->assertNotFound();
    $this->actingAs($b)->delete("/media/{$mine->id}")->assertNotFound();

    $this->actingAs($a)->get('/media')->assertInertia(fn ($p) => $p->has('items.data', 1));
    $this->actingAs($admin)->get('/media')->assertInertia(fn ($p) => $p->has('items.data', 1)->where('items.data.0.uploader', $a->name));
    expect($mine->fresh()->alt_text)->toBe('a');
});

test('deleting media removes the record and every file', function () {
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'a']);
    $m = Media::firstOrFail();
    $paths = $m->allPaths();

    $this->actingAs($writer)->delete("/media/{$m->id}")->assertSessionHasNoErrors();

    expect(Media::count())->toBe(0);
    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

test('alt text can be edited by the owner and is required', function () {
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'a']);
    $m = Media::firstOrFail();

    $this->actingAs($writer)->put("/media/{$m->id}", ['alt_text' => 'better'])->assertSessionHasNoErrors();
    expect($m->fresh()->alt_text)->toBe('better');
    $this->actingAs($writer)->put("/media/{$m->id}", ['alt_text' => ''])->assertSessionHasErrors('alt_text');
});

test('the picker gets a JSON listing and guests are redirected', function () {
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'a']);

    $this->actingAs($writer)->getJson('/media?kind=image')->assertOk()->assertJsonPath('data.0.kind', 'image');
    $this->actingAs($writer)->getJson('/media?kind=pdf')->assertOk()->assertJsonCount(0, 'data');
    auth()->logout();
    $this->get('/media')->assertRedirect('/login');
});

test('NFR-12 uploads are capped at 20 per hour per user', function () {
    $writer = User::factory()->create();

    foreach (range(1, 20) as $i) {
        $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image("{$i}.jpg", 20, 20), 'alt_text' => 'x'])->assertSessionHasNoErrors();
    }

    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('21.jpg'), 'alt_text' => 'x'])->assertStatus(429);
});

test('media list does not query per row', function () {
    $writer = User::factory()->create();
    foreach (range(1, 8) as $i) {
        Media::create(['uuid' => Str::uuid(), 'uploader_id' => $writer->id, 'path' => "uploads/2026/10/{$i}.jpg", 'original_name' => "{$i}.jpg", 'mime' => 'image/jpeg', 'size' => 1, 'alt_text' => 'x']);
    }

    DB::enableQueryLog();
    $this->actingAs(adminUser())->get('/media')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});

test('FR-27 media used as a featured image or inside content cannot be deleted', function () {
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.jpg'), 'alt_text' => 'a']);
    $m = Media::firstOrFail();

    $post = Post::factory()->by($writer)->create(['featured_media_id' => $m->id]);
    $this->actingAs($writer)->delete("/media/{$m->id}")->assertSessionHasErrors('media');
    expect(Media::count())->toBe(1);

    $post->update(['featured_media_id' => null]);
    $post->update(['body' => '<figure><img src="/storage/'.$m->path.'" alt="a"></figure>']);
    $this->actingAs($writer)->delete("/media/{$m->id}")->assertSessionHasErrors('media');

    $post->forceDelete();
    Page::factory()->create(['body' => '<p>see /files/'.$m->uuid.'</p>']);
    $this->actingAs($writer)->delete("/media/{$m->id}")->assertSessionHasErrors('media');

    Page::query()->forceDelete();
    $this->actingAs($writer)->delete("/media/{$m->id}")->assertSessionHasNoErrors();
    expect(Media::count())->toBe(0);
});

test('a GIF keeps its format; only JPG, PNG and WebP become WebP', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->image('a.gif', 40, 40), 'alt_text' => 'x'])->assertSessionHasNoErrors();

    expect(Media::firstOrFail()->path)->toEndWith('.gif');
});

test('media:webp converts old JPG/PNG media, rewrites post and page URLs, keeps the old files and cleans up on delete', function () {
    $disk = Storage::disk('public');
    $uuid = (string) Str::uuid();
    $old = "uploads/2026/10/{$uuid}.png";
    $png = UploadedFile::fake()->image('old.png', 900, 600)->get();
    foreach (['', '-thumb', '-medium', '-large'] as $suffix) {
        $disk->put(str_replace('.png', "{$suffix}.png", $old), $png);
    }
    $user = User::factory()->create();
    $media = Media::create(['uuid' => $uuid, 'uploader_id' => $user->id, 'path' => $old, 'original_name' => 'old.png', 'mime' => 'image/png', 'size' => strlen($png), 'width' => 900, 'height' => 600, 'alt_text' => 'old']);
    $post = Post::factory()->create(['body' => "<p><img src=\"/storage/uploads/2026/10/{$uuid}-large.png\"></p>"]);
    $page = Page::factory()->create(['body' => "<p><img src=\"/storage/{$old}\"></p>"]);

    $this->artisan('media:webp', ['--dry-run' => true])->expectsOutputToContain('1 image(s) would be converted')->assertSuccessful();
    expect($media->fresh()->path)->toBe($old);

    $this->artisan('media:webp')->expectsOutputToContain('1 image(s) converted')->assertSuccessful();

    $media->refresh();
    expect($media->mime)->toBe('image/webp')->and($media->path)->toBe("uploads/2026/10/{$uuid}.webp");
    foreach ($media->allPaths() as $path) {
        $disk->assertExists($path);
    }
    expect($post->fresh()->body)->toContain("src=\"/storage/uploads/2026/10/{$uuid}-large.webp\"")
        ->and($page->fresh()->body)->toContain("src=\"/storage/uploads/2026/10/{$uuid}.webp\"");
    $disk->assertExists($old); // old files stay until the media is deleted

    $this->artisan('media:webp')->expectsOutputToContain('0 image(s) converted')->assertSuccessful();

    $media->deleteFiles();
    expect($disk->allFiles('uploads'))->toBe([]);
});
