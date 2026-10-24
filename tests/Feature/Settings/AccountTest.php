<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile can be updated and an email change is audited', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

    expect($user->fresh()->email)->toBe('new@example.com');
    expect(AuditLog::where('action', 'profile.email_changed')->exists())->toBeTrue();
});

test('profile cannot escalate role or status via mass assignment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'X', 'email' => $user->email, 'role' => 'admin', 'status' => 'active',
    ]);

    expect($user->fresh()->role)->toBe('writer');
});

test('account deletion route is gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/settings/profile', ['password' => PW])->assertStatus(405);
});

test('TC-F01-06 changing the password revokes other sessions only', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'other1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'other2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => PW, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->whereIn('id', ['other1', 'other2'])->count())->toBe(0);
    expect(AuditLog::where('action', 'password.changed')->exists())->toBeTrue();
});

test('password change requires the current password and 12+ chars', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'nope', 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password',
    ])->assertSessionHasErrors('current_password');

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => PW, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});

test('users can list and revoke their own sessions but not others', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'mine', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'theirs', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($user)->get('/settings/sessions')->assertOk();

    $this->actingAs($user)->delete('/settings/sessions/theirs');
    expect(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();

    $this->actingAs($user)->delete('/settings/sessions/mine');
    expect(DB::table('sessions')->where('id', 'mine')->exists())->toBeFalse();
});
