<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function admin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

test('TC-F02-01 writers and guests cannot reach user management', function () {
    $writer = User::factory()->create();

    $this->actingAs($writer)->get('/admin/users')->assertForbidden();
    $this->actingAs($writer)->post('/admin/users', [])->assertForbidden();
    auth()->logout();
    $this->get('/admin/users')->assertRedirect('/login');
});

test('TC-F02-03 admin invites a user who activates via the emailed link', function () {
    Notification::fake();
    $admin = admin();

    $this->actingAs($admin)->post('/admin/users', ['name' => 'Wendy Writer', 'email' => 'wendy@example.com', 'role' => 'writer'])
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'wendy@example.com')->firstOrFail();
    expect($user->status)->toBe('invited')->and($user->role)->toBe('writer');

    $token = null;
    Notification::assertSentTo($user, InviteNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });
    auth()->logout();

    $this->get("/invite/{$token}?email=wendy@example.com")->assertOk();
    $this->post('/invite', [
        'token' => $token, 'email' => 'wendy@example.com',
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect('/login');

    expect($user->fresh()->status)->toBe('active');
    $this->post('/login', ['email' => 'wendy@example.com', 'password' => 'a-brand-new-password'])->assertRedirect('/dashboard');
    expect(AuditLog::where('action', 'user.invited')->exists())->toBeTrue();
});

test('invite links expire after 48 hours and activate only invited accounts', function () {
    Notification::fake();
    $admin = admin();
    $this->actingAs($admin)->post('/admin/users', ['name' => 'Old', 'email' => 'old@example.com', 'role' => 'writer']);
    $user = User::where('email', 'old@example.com')->firstOrFail();
    $token = null;
    Notification::assertSentTo($user, InviteNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });
    auth()->logout();

    $this->travel(49)->hours();
    $this->post('/invite', [
        'token' => $token, 'email' => 'old@example.com',
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasErrors('email');
    expect($user->fresh()->status)->toBe('invited');
});

test('invite rejects duplicate emails and bad roles', function () {
    $admin = admin();

    $this->actingAs($admin)->post('/admin/users', ['name' => 'Dup', 'email' => $admin->email, 'role' => 'writer'])
        ->assertSessionHasErrors('email');
    $this->actingAs($admin)->post('/admin/users', ['name' => 'Bad', 'email' => 'bad@example.com', 'role' => 'superuser'])
        ->assertSessionHasErrors('role');
});

test('TC-F02-02 the last active admin cannot be demoted or disabled', function () {
    $admin = admin();
    $second = User::factory()->admin()->withTwoFactor()->create();
    $second->forceFill(['status' => 'disabled'])->save(); // only $admin is active

    $this->actingAs($admin)->post("/admin/users/{$admin->id}/disable")->assertSessionHasErrors('user');
    $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'role' => 'writer'])
        ->assertSessionHasErrors('user');
    expect($admin->fresh()->role)->toBe('admin')->and($admin->fresh()->status)->toBe('active');
});

test('an admin can demote another admin and the change is audited', function () {
    $admin = admin();
    $other = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->put("/admin/users/{$other->id}", ['name' => $other->name, 'role' => 'writer'])
        ->assertSessionHasNoErrors();

    expect($other->fresh()->role)->toBe('writer');
    expect(AuditLog::where('action', 'user.role_changed')->where('object_id', $other->id)->exists())->toBeTrue();
});

test('TC-F02-04 disabling a user ends their sessions and blocks login', function () {
    $admin = admin();
    $writer = User::factory()->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $writer->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->post("/admin/users/{$writer->id}/disable")->assertSessionHasNoErrors();

    expect($writer->fresh()->status)->toBe('disabled');
    expect(DB::table('sessions')->where('user_id', $writer->id)->count())->toBe(0);
    auth()->logout();
    $this->post('/login', ['email' => $writer->email, 'password' => PW])->assertSessionHasErrors('email');

    $this->actingAs($admin)->post("/admin/users/{$writer->id}/enable");
    expect($writer->fresh()->status)->toBe('active');
});

test('admins cannot change their own role or disable themselves', function () {
    $admin = admin();
    User::factory()->admin()->withTwoFactor()->create(); // a second admin exists

    $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => 'x', 'role' => 'writer'])->assertSessionHasErrors('user');
    $this->actingAs($admin)->post("/admin/users/{$admin->id}/disable")->assertSessionHasErrors('user');
});

test('force reset kills the old password and sessions and emails a link', function () {
    Notification::fake();
    $admin = admin();
    $writer = User::factory()->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $writer->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->post("/admin/users/{$writer->id}/force-reset")->assertSessionHasNoErrors();
    auth()->logout();

    Notification::assertSentTo($writer, ResetPassword::class);
    expect(DB::table('sessions')->where('user_id', $writer->id)->count())->toBe(0);
    $this->post('/login', ['email' => $writer->email, 'password' => PW])->assertSessionHasErrors('email');
});

test('user list searches by name or email in constant queries', function () {
    $admin = admin();
    User::factory()->count(20)->create();
    User::factory()->create(['name' => 'Zed Findme']);

    DB::enableQueryLog();
    $this->actingAs($admin)->get('/admin/users?q=Findme')->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 1));
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});
