<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Illuminate\Support\Facades\Notification;

const PW = 'correct-horse-battery';

test('TC-F01-01 writer logs in with correct credentials', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => PW])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::where('action', 'login.success')->where('actor_id', $user->id)->exists())->toBeTrue();
});

test('TC-F01-02 unknown email and wrong password give identical errors', function () {
    $user = User::factory()->create();

    $a = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-123']);
    $b = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password-123']);

    $a->assertSessionHasErrors(['email' => __('auth.failed')]);
    $b->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
});

test('TC-F01-03 five failures lock the account and block even the right password', function () {
    Notification::fake();
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-123']);
    }

    expect($user->fresh()->locked_until)->not->toBeNull()->and($user->fresh()->locked_until->isFuture())->toBeTrue();
    Notification::assertSentTo($user, AccountLockedNotification::class);

    $this->post('/login', ['email' => $user->email, 'password' => PW])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();

    $this->travel(16)->minutes();
    $this->post('/login', ['email' => $user->email, 'password' => PW])->assertRedirect('/dashboard');
});

test('disabled and invited accounts cannot log in', function () {
    $disabled = User::factory()->disabled()->create();
    $invited = User::factory()->invited()->create();

    $this->post('/login', ['email' => $disabled->email, 'password' => PW])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $invited->email, 'password' => PW])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('NFR-08 login is rate limited per IP with 429', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/login', ['email' => "x{$i}@example.com", 'password' => 'wrong-password-123']);
    }

    $this->post('/login', ['email' => 'x@example.com', 'password' => 'wrong-password-123'])
        ->assertStatus(429)->assertHeader('Retry-After');
});

test('TC-F01-07 session idle past the role limit is logged out', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->withSession(['last_seen' => now()->subMinutes(31)->timestamp])
        ->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();

    $writer = User::factory()->create();
    $this->actingAs($writer)->withSession(['last_seen' => now()->subMinutes(31)->timestamp])
        ->get('/dashboard')->assertOk(); // writers get 120 minutes
});

test('a user disabled mid-session is kicked on the next request', function () {
    $user = User::factory()->disabled()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
});

test('there is no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

test('logout is audited', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    expect(AuditLog::where('action', 'logout')->where('actor_id', $user->id)->exists())->toBeTrue();
});
