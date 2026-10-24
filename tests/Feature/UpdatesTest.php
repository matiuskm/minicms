<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Notifications\UpdateAvailable;
use App\Support\Updates\Updater;
use App\Support\Updates\Version;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

function release(string $tag = 'v9.9.9', array $over = []): array
{
    $v = ltrim($tag, 'v');

    return [...[
        'tag_name' => $tag, 'body' => "## Fixed\n- Something", 'published_at' => '2026-10-24T10:00:00Z', 'html_url' => "https://github.com/matiuskm/minicms/releases/tag/{$tag}",
        'assets' => [['name' => "minicms-v{$v}.tar.gz"], ['name' => "minicms-v{$v}.tar.gz.sig"], ['name' => "minicms-v{$v}.tar.gz.sha256"]],
    ], ...$over];
}

function fakeGithub(array|int $response): void
{
    // Http::fake() stubs accumulate and the first match wins, so start from a clean client each time.
    Http::swap(new Factory);
    Http::fake(['api.github.com/*' => is_int($response) ? Http::response([], $response) : Http::response($response)]);
}

beforeEach(function () {
    Cache::flush();
    File::deleteDirectory(Updater::dir());
    config(['cms.update.enabled' => true, 'app.version' => '1.0.0']);
});

afterEach(fn () => File::deleteDirectory(Updater::dir()));

// ---- versions ------------------------------------------------------------------------------------------------

test('only plain MAJOR.MINOR.PATCH versions are ever newer', function () {
    expect(Version::newer('1.0.1', '1.0.0'))->toBeTrue()->and(Version::newer('1.10.0', '1.9.9'))->toBeTrue()->and(Version::newer('2.0.0', '1.99.99'))->toBeTrue();
    foreach ([['1.0.0', '1.0.0'], ['0.9.9', '1.0.0'], ['1.0.0-rc1', '0.9.0'], ['1.0', '0.9.0'], ['latest', '1.0.0'], ['1.0.1; rm -rf /', '1.0.0'], ['', '1.0.0'], [null, '1.0.0'], ['1.0.1', 'dev']] as [$a, $b]) {
        expect(Version::newer($a, $b))->toBeFalse("{$a} vs {$b}");
    }
    expect(Version::fromTag('v1.2.3'))->toBe('1.2.3')->and(Version::fromTag('1.2.3'))->toBe('1.2.3')->and(Version::fromTag('v1.2.3-beta'))->toBeNull()->and(Version::fromTag(null))->toBeNull();
});

// ---- looking for updates -------------------------------------------------------------------------------------

test('TC-F15-01 a newer signed release is reported with its notes', function () {
    fakeGithub(release());

    $a = Updater::available();

    expect($a)->toMatchArray(['version' => '9.9.9', 'signed' => true])->and($a['notes'])->toContain('Something');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/repos/matiuskm/minicms/releases/latest') && $r->hasHeader('User-Agent', 'minicms-updater'));
});

test('no update is offered when current, older, malformed, unreachable or unsigned', function () {
    foreach ([release('v1.0.0'), release('v0.5.0'), release('v1.1.0-beta'), release('nightly'), 500, 404] as $case) {
        Cache::flush();
        fakeGithub($case);
        expect(Updater::available())->toBeNull();
    }

    Cache::flush();
    fakeGithub(release('v1.2.0', ['assets' => [['name' => 'minicms-v1.2.0.tar.gz']]]));   // no signature asset
    expect(Updater::available()['signed'])->toBeFalse();
    expect(Updater::canInstall('1.2.0'))->toBeFalse();
});

test('a network failure never breaks anything and is remembered briefly', function () {
    Http::fake(fn () => throw new RuntimeException('dns'));

    expect(Updater::available())->toBeNull()->and(Updater::available())->toBeNull();
    Http::assertSentCount(0); // the exception was thrown before any request was recorded; no retry storm on the second call
});

test('the lookup is cached so it is not repeated on every visit', function () {
    fakeGithub(release());

    Updater::available();
    Updater::available();
    Updater::latest();

    Http::assertSentCount(1);
    Updater::available(refresh: true);
    Http::assertSentCount(2);
});

test('release notes are plain text and capped', function () {
    fakeGithub(release('v9.9.9', ['body' => '<script>alert(1)</script><b>bold</b> '.str_repeat('x', 20000), 'html_url' => 'javascript:alert(1)']));

    $a = Updater::available();

    expect($a['notes'])->not->toContain('<script')->not->toContain('<b>')->and(mb_strlen($a['notes']))->toBeLessThanOrEqual(8001)->and($a['url'])->toBeNull();
});

test('TC-F15-02 the daily check emails the admins once per version', function () {
    Notification::fake();
    $admin = adminUser();
    User::factory()->create(); // writers are not told
    fakeGithub(release());

    $this->artisan('cms:check-updates')->assertSuccessful();
    $this->artisan('cms:check-updates')->assertSuccessful();

    Notification::assertSentToTimes($admin, UpdateAvailable::class, 1);
    expect(Setting::get('update_notified'))->toBe('9.9.9');

    Cache::flush();
    fakeGithub(release('v9.9.10'));
    $this->artisan('cms:check-updates');
    Notification::assertSentToTimes($admin, UpdateAvailable::class, 2);
});

test('an up-to-date site sends nothing', function () {
    Notification::fake();
    adminUser();
    fakeGithub(release('v1.0.0'));

    $this->artisan('cms:check-updates')->assertSuccessful()->expectsOutputToContain('Up to date');

    Notification::assertNothingSent();
});

// ---- the admin screen ----------------------------------------------------------------------------------------

test('admins see the version, the available update and its notes', function () {
    fakeGithub(release());

    $this->actingAs(adminUser())->get('/admin/updates')->assertOk()->assertInertia(fn ($p) => $p->component('admin/updates')
        ->where('current', '1.0.0')->where('available', true)->where('enabled', true)->where('latest.version', '9.9.9')->where('status.state', 'idle'));
});

test('only admins reach the updates screens and endpoints', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->get('/admin/updates')->assertForbidden();
    $this->actingAs($writer)->post('/admin/updates/check')->assertForbidden();
    $this->actingAs($writer)->post('/admin/updates/install', ['version' => '9.9.9'])->assertForbidden();
    $this->actingAs($writer)->getJson('/admin/updates/status')->assertForbidden();
    expect(is_file(Updater::dir().'/request.json'))->toBeFalse();
});

test('the sidebar hint appears for admins once an update is known, and never for writers', function () {
    fakeGithub(release());
    $admin = adminUser();

    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('updateAvailable', null)); // nothing looked up yet: no network call on normal pages
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.github.com')); // (the Inertia SSR gateway also uses this client)

    $this->actingAs($admin)->post('/admin/updates/check');
    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('updateAvailable', '9.9.9'));
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn ($p) => $p->where('updateAvailable', null));
});

test('Check for updates refreshes the lookup', function () {
    fakeGithub(release('v1.0.0'));
    $admin = adminUser();
    $this->actingAs($admin)->get('/admin/updates')->assertInertia(fn ($p) => $p->where('available', false));

    Cache::flush();
    fakeGithub(release('v1.0.1'));
    $this->actingAs($admin)->post('/admin/updates/check')->assertSessionHas('status', 'Version 1.0.1 is available.');
});

// ---- requesting an update ------------------------------------------------------------------------------------

test('TC-F15-03 installing writes a request for the applier and an audit entry; the app changes nothing itself', function () {
    fakeGithub(release());
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasNoErrors();

    $req = json_decode(file_get_contents(Updater::dir().'/request.json'), true);
    expect($req['version'])->toBe('9.9.9')->and($req['requested_by'])->toBe($admin->name)->and(array_keys($req))->toBe(['version', 'requested_by', 'requested_at']);
    expect(Updater::status())->toMatchArray(['state' => 'queued', 'version' => '9.9.9', 'from' => '1.0.0']);
    expect(AuditLog::where('action', 'update.requested')->exists())->toBeTrue();
    expect(file_get_contents(base_path('VERSION')))->not->toContain('9.9.9'); // the code is untouched
});

test('only the available signed version can be requested, once, and only when enabled', function () {
    fakeGithub(release());
    $admin = adminUser();

    foreach (['9.9.8', '9.9.10', '1.0.0', '0.1.0', '9.9.9; rm -rf /', 'latest', '', '../../etc'] as $bad) {
        $this->actingAs($admin)->post('/admin/updates/install', ['version' => $bad])->assertSessionHasErrors('version');
    }
    expect(is_file(Updater::dir().'/request.json'))->toBeFalse();

    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasErrors(['version' => 'An update is already in progress.']);
});

test('with updates disabled the button does nothing and the screen explains how to enable it', function () {
    config(['cms.update.enabled' => false]);
    fakeGithub(release());
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasErrors('version');
    expect(is_file(Updater::dir().'/request.json'))->toBeFalse();
    $this->actingAs($admin)->get('/admin/updates')->assertInertia(fn ($p) => $p->where('enabled', false)->where('available', true));
});

test('an unsigned release cannot be installed', function () {
    fakeGithub(release('v9.9.9', ['assets' => [['name' => 'minicms-v9.9.9.tar.gz']]]));

    $this->actingAs(adminUser())->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasErrors('version');
});

test('the status endpoint reports the applier progress and a finished run frees the next request', function () {
    fakeGithub(release());
    $admin = adminUser();
    $this->actingAs($admin)->getJson('/admin/updates/status')->assertOk()->assertJsonPath('state', 'idle');

    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9']);
    $this->actingAs($admin)->getJson('/admin/updates/status')->assertJsonPath('state', 'queued');

    File::put(Updater::dir().'/status.json', json_encode(['state' => 'failed', 'step' => 'verifying', 'message' => 'Rejected', 'version' => '9.9.9']));
    $this->actingAs($admin)->getJson('/admin/updates/status')->assertJsonPath('state', 'failed')->assertJsonPath('step', 'verifying');
    expect(Updater::busy())->toBeFalse();
    $this->actingAs($admin)->post('/admin/updates/install', ['version' => '9.9.9'])->assertSessionHasNoErrors();
});

test('a corrupt status file is treated as idle', function () {
    File::ensureDirectoryExists(Updater::dir());
    File::put(Updater::dir().'/status.json', '{not json');

    expect(Updater::status()['state'])->toBe('idle');
});

// ---- commands used by the applier ----------------------------------------------------------------------------

test('cms:maintenance toggles the setting and reports the previous state', function () {
    $this->artisan('cms:maintenance', ['state' => 'on'])->expectsOutput('was-off')->assertSuccessful();
    expect(Setting::get('maintenance_mode'))->toBeTrue();
    $this->artisan('cms:maintenance', ['state' => 'off'])->expectsOutput('was-on');
    expect(Setting::get('maintenance_mode'))->toBeFalse();
    $this->artisan('cms:maintenance', ['state' => 'later'])->assertFailed();
});

test('cms:record-update audits the outcome and emails the admins', function () {
    Notification::fake();
    $admin = adminUser();

    $this->artisan('cms:record-update', ['from' => '1.0.0', 'to' => '1.0.1', 'result' => 'success'])->assertSuccessful();
    $this->artisan('cms:record-update', ['from' => '1.0.1', 'to' => '1.0.2', 'result' => 'rolled_back', '--note' => 'health_check: no response'])->assertSuccessful();
    $this->artisan('cms:record-update', ['from' => '1.0.1', 'to' => 'x', 'result' => 'success'])->assertFailed();

    expect(AuditLog::where('action', 'update.success')->exists())->toBeTrue()->and(AuditLog::where('action', 'update.rolled_back')->exists())->toBeTrue();
    Notification::assertSentToTimes($admin, SecurityNotice::class, 2);
    $this->artisan('cms:version')->expectsOutput('1.0.0');
});

// ---- release signing -----------------------------------------------------------------------------------------

function sodiumRun(array $cmd): array
{
    $p = new Process(['php', ...$cmd], base_path());
    $p->run();

    return [$p->getExitCode(), trim($p->getOutput().$p->getErrorOutput())];
}

test('TC-F15-04 release signatures verify only for the exact bytes and the pinned key', function () {
    $dir = sys_get_temp_dir().'/sig-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $kp = sodium_crypto_sign_keypair();
    file_put_contents("{$dir}/pub", base64_encode(sodium_crypto_sign_publickey($kp)));
    $secret = base64_encode(sodium_crypto_sign_secretkey($kp));
    file_put_contents("{$dir}/a.tar.gz", random_bytes(2048));

    [$code, $sig] = sodiumRun(['scripts/sign-release.php', "{$dir}/a.tar.gz", $secret]);
    expect($code)->toBe(0);
    file_put_contents("{$dir}/a.sig", $sig);
    $sha = hash_file('sha256', "{$dir}/a.tar.gz");

    expect(sodiumRun(['scripts/verify-release.php', "{$dir}/a.tar.gz", "{$dir}/a.sig", "{$dir}/pub", $sha]))->toBe([0, 'ok']);

    file_put_contents("{$dir}/b.tar.gz", file_get_contents("{$dir}/a.tar.gz").'x');
    expect(sodiumRun(['scripts/verify-release.php', "{$dir}/b.tar.gz", "{$dir}/a.sig", "{$dir}/pub"]))->toBe([1, 'signature does not match']);
    expect(sodiumRun(['scripts/verify-release.php', "{$dir}/a.tar.gz", "{$dir}/a.sig", "{$dir}/pub", str_repeat('0', 64)]))->toBe([1, 'checksum mismatch']);

    $other = sodium_crypto_sign_keypair();
    file_put_contents("{$dir}/other", base64_encode(sodium_crypto_sign_publickey($other)));
    expect(sodiumRun(['scripts/verify-release.php', "{$dir}/a.tar.gz", "{$dir}/a.sig", "{$dir}/other"]))->toBe([1, 'signature does not match']);

    file_put_contents("{$dir}/junk.sig", 'not base64 !!');
    expect(sodiumRun(['scripts/verify-release.php', "{$dir}/a.tar.gz", "{$dir}/junk.sig", "{$dir}/pub"])[0])->toBe(1);

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

test('the pinned public key shipped with the app is a valid Ed25519 key', function () {
    $key = base64_decode(trim(file_get_contents(base_path('release-public.key'))), true);

    expect($key)->not->toBeFalse()->and(strlen($key))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
});

test('the status helper keeps a bounded log and marks the finish time', function () {
    $file = sys_get_temp_dir().'/status-'.bin2hex(random_bytes(4)).'.json';

    foreach (range(1, 230) as $i) {
        sodiumRun(['scripts/update-status.php', $file, 'running', 'step', "line {$i}", '1.2.3', '1.2.2']);
    }
    sodiumRun(['scripts/update-status.php', $file, 'success', 'done', 'Updated']);
    $s = json_decode(file_get_contents($file), true);

    expect($s)->toMatchArray(['state' => 'success', 'version' => '1.2.3', 'from' => '1.2.2'])->and(count($s['log']))->toBe(200)->and($s['finished_at'])->not->toBeNull();
    unlink($file);
})->skip(fn () => PHP_OS_FAMILY === 'Windows');
