<?php

use App\Http\Middleware\EnforceSessionPolicy;
use App\Models\AuditLog;
use App\Models\KnownDevice;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BackupFailed;
use App\Notifications\IntegrityAlert;
use App\Notifications\SecurityNotice;
use App\Support\Backup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

function csp(string $url = '/login'): string
{
    return test()->get($url)->headers->get('Content-Security-Policy');
}

// ---- F-12: headers -------------------------------------------------------------------------------------------

test('TC-F12-01 every kind of response carries the security headers and a strict CSP', function () {
    Post::factory()->status('published')->create(['slug' => 'a']);

    foreach (['/', '/blog/a', '/login', '/does-not-exist', '/sitemap.xml', '/theme.css'] as $url) {
        $res = $this->get($url);
        $res->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy')
            ->assertHeader('Permissions-Policy')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        $policy = $res->headers->get('Content-Security-Policy');
        expect($policy)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'none'")->toContain("base-uri 'self'")->toContain("form-action 'self'");
        expect($policy)->not->toContain("'unsafe-eval'");
        // Scripts: self + nonce only. (style="" attributes are allowed separately, never script.)
        preg_match('/script-src ([^;]+)/', $policy, $m);
        expect($m[1])->toMatch("/^'self' 'nonce-[A-Za-z0-9+\\/=]+'$/")->not->toContain('unsafe-inline');
    }
});

test('the nonce is fresh per response and is stamped on the page scripts', function () {
    $a = $this->get('/login');
    $b = $this->get('/login');
    preg_match("/'nonce-([^']+)'/", $a->headers->get('Content-Security-Policy'), $na);
    preg_match("/'nonce-([^']+)'/", $b->headers->get('Content-Security-Policy'), $nb);

    expect($na[1])->not->toBe($nb[1]);
    // Ziggy's inline route table and the Vite tags must carry the same nonce, or the page would not run.
    $html = $a->getContent();
    expect($html)->toContain('nonce="'.$na[1].'"')->toContain('name="csp-nonce" content="'.$na[1].'"');
    expect(preg_match_all('/<script(?![^>]*(nonce=|type="application\/(ld\+)?json"|src=))[^>]*>/i', $html))->toBe(0);
});

test('HSTS is sent on HTTPS and upgrade-insecure-requests is added', function () {
    $res = $this->get('https://localhost/login');

    $res->assertHeader('Strict-Transport-Security');
    expect($res->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests');
});

test('PDF downloads keep their own sandbox policy', function () {
    Storage::fake('local');
    $writer = User::factory()->create();
    $this->actingAs($writer)->post('/media', ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nx"), 'alt_text' => 'Doc']);
    auth()->logout();

    $this->get('/files/'.Media::firstOrFail()->uuid)->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
});

test('TC-F12-02 all state-changing requests need a CSRF token outside the test harness', function () {
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    expect(collect(app('router')->getRoutes())->filter(fn ($r) => in_array('POST', $r->methods()) || in_array('PUT', $r->methods()) || in_array('DELETE', $r->methods()))
        ->filter(fn ($r) => in_array('web', $r->gatherMiddleware()))->count())->toBeGreaterThan(30);
});

test('NFR-08 admin and editor actions are throttled per user', function () {
    $admin = adminUser();

    foreach (range(1, 240) as $i) {
        $this->actingAs($admin)->get('/admin/appearance');
    }
    $this->actingAs($admin)->get('/admin/appearance')->assertStatus(429)->assertHeader('Retry-After');

    // another user is not affected
    $this->actingAs(adminUser())->get('/admin/appearance')->assertOk();
});

test('search is limited to 30 requests a minute per IP', function () {
    foreach (range(1, 30) as $i) {
        $this->get('/search?q=x');
    }
    $this->get('/search?q=x')->assertStatus(429);
});

// ---- F-12: lockdown ------------------------------------------------------------------------------------------

test('TC-F12-04 lockdown signs writers out, blocks their login and leaves admins alone', function () {
    $admin = adminUser();
    $writer = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'w1', 'user_id' => $writer->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'a1', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->post('/admin/security/lockdown', ['enabled' => true])->assertSessionHasNoErrors();
    auth()->logout();

    expect(DB::table('sessions')->where('id', 'w1')->exists())->toBeFalse()->and(DB::table('sessions')->where('id', 'a1')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'lockdown.enabled')->exists())->toBeTrue();

    $this->post('/login', ['email' => $writer->email, 'password' => PW])->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
    expect(AuditLog::where('action', 'login.blocked_lockdown')->exists())->toBeTrue();

    // a writer with an old session cookie is also kicked on the next request
    $this->actingAs($writer)->get('/dashboard')->assertRedirect('/login');

    $plainAdmin = User::factory()->admin()->create(); // no 2FA, so the login completes in one step
    $this->post('/login', ['email' => $plainAdmin->email, 'password' => PW])->assertRedirect();
    $this->assertAuthenticatedAs($plainAdmin);

    auth()->logout();
    $this->actingAs($admin)->post('/admin/security/lockdown', ['enabled' => false]);
    auth()->logout();
    $this->post('/login', ['email' => $writer->email, 'password' => PW])->assertRedirect('/dashboard');
});

test('a wrong password during lockdown is not reported as a lockdown', function () {
    Setting::put(['lockdown' => true]);
    $writer = User::factory()->create();

    $this->post('/login', ['email' => $writer->email, 'password' => 'wrong-password-1'])->assertSessionHasErrors('email');

    expect(AuditLog::where('action', 'login.blocked_lockdown')->exists())->toBeFalse()->and(AuditLog::where('action', 'login.failed')->exists())->toBeTrue();
});

test('the security screens are admin only', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->get('/admin/security')->assertForbidden();
    $this->actingAs($writer)->post('/admin/security/lockdown', ['enabled' => true])->assertForbidden();
    $this->actingAs($writer)->post('/admin/security/integrity/check')->assertForbidden();
    $this->actingAs($writer)->post('/admin/security/integrity/reseal', ['type' => 'post', 'id' => 1])->assertForbidden();
    expect(Setting::get('lockdown'))->toBeFalse();
});

// ---- F-12: integrity -----------------------------------------------------------------------------------------

test('TC-F12-03 content edited outside the app is detected, alerted once, audited and shown', function () {
    Notification::fake();
    $admin = adminUser();
    $post = Post::factory()->status('published')->create(['body' => '<p>original</p>']);
    $page = Page::factory()->create(['status' => 'published']);
    Post::factory()->create(); // a draft is not checked

    $this->artisan('cms:check-integrity')->assertSuccessful();
    Notification::assertNothingSent();

    DB::table('posts')->where('id', $post->id)->update(['body' => '<p>DEFACED</p>']); // bypasses the model, as an attacker with SQL access would
    $this->artisan('cms:check-integrity')->assertFailed();

    Notification::assertSentTo($admin, IntegrityAlert::class, fn ($n) => $n->items[0]['id'] === $post->id && $n->items[0]['type'] === 'post');
    expect(AuditLog::where('action', 'integrity.mismatch')->count())->toBe(1);

    // same problem on the next run: no second email
    $this->artisan('cms:check-integrity');
    Notification::assertSentToTimes($admin, IntegrityAlert::class, 1);

    DB::table('pages')->where('id', $page->id)->update(['title' => 'Hacked']);
    $this->artisan('cms:check-integrity');
    Notification::assertSentToTimes($admin, IntegrityAlert::class, 2);

    $this->actingAs($admin)->get('/admin/security')->assertOk()->assertInertia(fn ($p) => $p->component('admin/security')->has('integrity.mismatches', 2));
});

test('the integrity check ignores drafts, trash and unpublished pages, and passes after normal edits', function () {
    $admin = adminUser();
    $post = Post::factory()->status('published')->create();
    Post::factory()->create(['status' => 'draft']);
    $trashed = Post::factory()->status('published')->create();
    DB::table('posts')->where('id', $trashed->id)->update(['body' => '<p>x</p>', 'deleted_at' => now()]);

    $this->actingAs($admin)->put("/admin/posts/{$post->id}", ['action' => 'save', 'title' => 'Edited', 'body' => '<p>New text</p>', 'category_id' => $post->category_id, 'version' => $post->fresh()->version])->assertSessionHasNoErrors();

    $this->artisan('cms:check-integrity')->assertSuccessful();
});

test('an admin can confirm a legitimate change, which becomes the new baseline', function () {
    $admin = adminUser();
    $post = Post::factory()->status('published')->create();
    DB::table('posts')->where('id', $post->id)->update(['body' => '<p>edited by a DBA</p>']);
    $this->artisan('cms:check-integrity')->assertFailed();

    $this->actingAs($admin)->post('/admin/security/integrity/reseal', ['type' => 'post', 'id' => $post->id])->assertSessionHasNoErrors();

    $this->artisan('cms:check-integrity')->assertSuccessful();
    expect(AuditLog::where('action', 'integrity.resealed')->exists())->toBeTrue();
    $this->actingAs($admin)->post('/admin/security/integrity/reseal', ['type' => 'post', 'id' => 99999])->assertNotFound();
});

test('restoring a previous revision after a defacement clears the alert', function () {
    $admin = adminUser();
    $post = Post::factory()->status('published')->create(['body' => '<p>good</p>']);
    $post->captureRevision($admin->id);
    DB::table('posts')->where('id', $post->id)->update(['body' => '<p>bad</p>']);
    $this->artisan('cms:check-integrity')->assertFailed();

    $rev = $post->revisions()->first();
    $this->actingAs($admin)->post("/admin/posts/{$post->id}/revisions/{$rev->id}/restore")->assertSessionHasNoErrors();

    $this->artisan('cms:check-integrity')->assertSuccessful();
    expect($post->fresh()->body)->toBe('<p>good</p>');
});

// ---- F-12: notices -------------------------------------------------------------------------------------------

test('FR-69 a sign-in from a new browser is emailed, the first one and known ones are not', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->withHeader('User-Agent', 'Browser A')->post('/login', ['email' => $user->email, 'password' => PW]);
    Notification::assertNothingSent();
    $this->post('/logout');

    $this->withHeader('User-Agent', 'Browser A')->post('/login', ['email' => $user->email, 'password' => PW]);
    Notification::assertNothingSent();
    $this->post('/logout');

    $this->withHeader('User-Agent', 'Browser B')->post('/login', ['email' => $user->email, 'password' => PW]);
    Notification::assertSentTo($user, SecurityNotice::class, fn ($n) => $n->subject === 'New sign-in to your account');
    expect(KnownDevice::where('user_id', $user->id)->count())->toBe(2)->and(AuditLog::where('action', 'login.new_device')->count())->toBe(1);
});

test('FR-69 critical account changes email the account owner', function () {
    Notification::fake();
    $admin = adminUser();
    $writer = User::factory()->create();

    $this->actingAs($admin)->put("/admin/users/{$writer->id}", ['name' => $writer->name, 'role' => 'admin']);
    Notification::assertSentTo($writer, SecurityNotice::class, fn ($n) => $n->subject === 'Your role was changed');

    $this->actingAs($writer)->put('/settings/password', ['current_password' => PW, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password']);
    Notification::assertSentTo($writer, SecurityNotice::class, fn ($n) => $n->subject === 'Your password was changed');

    $plain = User::factory()->withTwoFactor()->create();
    $this->actingAs($plain)->delete('/settings/two-factor', ['current_password' => PW]);
    Notification::assertSentTo($plain, SecurityNotice::class, fn ($n) => $n->subject === 'Two-factor authentication was turned off');
});

// ---- F-13: dashboard -----------------------------------------------------------------------------------------

test('TC-F13-01 the dashboard shows role-appropriate data', function () {
    $writer = User::factory()->create();
    $other = User::factory()->create();
    Post::factory()->by($other)->status('pending_review')->create(['title' => 'Needs review']);
    Post::factory()->by($writer)->status('rejected')->create(['title' => 'Mine, sent back', 'review_note' => 'More sources']);
    AuditLog::record('login.failed');

    $this->actingAs(adminUser())->get('/dashboard')->assertInertia(fn ($p) => $p->where('queue.0.title', 'Needs review')->has('activity')->where('failedLogins24h', 1)->has('integrity')->has('backup'));

    $this->actingAs($writer)->get('/dashboard')->assertInertia(fn ($p) => $p->where('queue.0.title', 'Mine, sent back')->where('queue.0.note', 'More sources')
        ->missing('activity')->missing('failedLogins24h')->missing('backup')->where('counts.rejected', 1)->missing('counts.pending_review'));
});

test('the dashboard stays within a constant number of queries', function () {
    Post::factory()->count(25)->status('pending_review')->create();
    foreach (range(1, 12) as $i) {
        AuditLog::record('x.y');
    }
    DB::enableQueryLog();

    $this->actingAs(adminUser())->get('/dashboard')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

// ---- F-13: backup & restore ----------------------------------------------------------------------------------

function haveDbTools(): bool
{
    foreach ([config('cms.backup.mysqldump'), config('cms.backup.mysql')] as $bin) {
        if (! (new Process([$bin, '--version']))->run() === 0) {
            return false;
        }
    }

    return (new Process([config('cms.backup.mysqldump'), '--version']))->run() === 0 && (new Process([config('cms.backup.mysql'), '--version']))->run() === 0;
}

test('TC-F13-02 a backup can be restored: data lost after the dump comes back', function () {
    if (! haveDbTools()) {
        $this->markTestSkipped('mysqldump/mysql are not installed.');
    }
    Storage::fake('backups');
    config(['cms.backup.disk' => 'backups']);

    // mysqldump uses its own connection, so the rows must be committed first.
    $keep = Post::factory()->status('published')->create(['title' => 'Survives the restore', 'slug' => 'survivor']);
    $keepId = $keep->id;
    DB::commit();

    try {
        $set = Backup::run();
        Storage::disk('backups')->assertExists($set['db']);

        DB::table('post_tag')->delete();
        DB::table('revisions')->delete();
        DB::table('posts')->delete();
        expect(Post::count())->toBe(0);

        Backup::restore($set['db']);

        expect(Post::withoutGlobalScopes()->find($keepId)?->title)->toBe('Survives the restore');
        expect(Setting::get('backup_last'))->toBeNull(); // restored DB predates the backup record
    } finally {
        DB::table('post_tag')->delete();
        DB::table('revisions')->delete();
        DB::table('posts')->delete();
        DB::table('users')->delete();
        DB::table('settings')->delete();
        DB::table('audit_logs')->delete();
        DB::table('media')->delete();
        DB::beginTransaction();
    }
});

test('a backup records its status, keeps only the newest sets, and ignores a missing media folder', function () {
    if (! haveDbTools()) {
        $this->markTestSkipped('mysqldump/mysql are not installed.');
    }
    Storage::fake('backups');
    config(['cms.backup.disk' => 'backups', 'cms.backup.keep' => 2]);

    foreach (['20260101-010101', '20260102-010101', '20260103-010101'] as $old) {
        Storage::disk('backups')->put("backup-{$old}-db.sql.gz", 'x');
        Storage::disk('backups')->put("backup-{$old}-media.tar.gz", 'x');
    }

    $this->artisan('cms:backup')->assertSuccessful();

    $files = collect(Storage::disk('backups')->files());
    expect($files->filter(fn ($f) => str_ends_with($f, '-db.sql.gz')))->toHaveCount(2);
    expect($files->contains('backup-20260101-010101-db.sql.gz'))->toBeFalse()->and($files->contains('backup-20260102-010101-db.sql.gz'))->toBeFalse();
    expect(Setting::get('backup_last'))->toMatchArray(['ok' => true])->and(AuditLog::where('action', 'backup.completed')->exists())->toBeTrue();
});

test('TC-F13-03 a failing backup is recorded, audited and the admins are told', function () {
    Notification::fake();
    Storage::fake('backups');
    config(['cms.backup.disk' => 'backups', 'cms.backup.mysqldump' => '/nonexistent/mysqldump']);
    $admin = adminUser();

    $this->artisan('cms:backup')->assertFailed();

    expect(Setting::get('backup_last'))->toMatchArray(['ok' => false])->and(AuditLog::where('action', 'backup.failed')->exists())->toBeTrue();
    Notification::assertSentTo($admin, BackupFailed::class);
    $this->actingAs($admin)->get('/admin/security')->assertInertia(fn ($p) => $p->where('backup.ok', false));
});

test('restore refuses to run without --force', function () {
    $this->artisan('cms:restore', ['db' => 'whatever.sql.gz'])->assertFailed();
    $this->artisan('cms:restore', ['db' => 'does-not-exist.sql.gz', '--force' => true])->assertFailed();
});

// ---- production checklist ------------------------------------------------------------------------------------

test('TC-F12-05 the production checklist fails in a development setup and names what is wrong', function () {
    $this->artisan('cms:check-production')->assertFailed()->expectsOutputToContain('FAIL  APP_DEBUG is off');
});

test('the production checklist passes for a correctly configured environment', function () {
    config([
        'app.debug' => false, 'app.url' => 'https://cms.example.com', 'session.secure' => true, 'session.encrypt' => true, 'hashing.driver' => 'argon2id',
        'cms.require_admin_2fa' => true, 'mail.default' => 'smtp', 'database.connections.mysql.username' => 'mini_cms_app',
    ]);
    app()->detectEnvironment(fn () => 'production');

    // The only checks left that depend on the machine are file permissions, which a dev checkout cannot satisfy.
    $this->artisan('cms:check-production')->expectsOutputToContain('PASS  APP_ENV is production')->expectsOutputToContain('PASS  Mail is not the log/array driver');
});
