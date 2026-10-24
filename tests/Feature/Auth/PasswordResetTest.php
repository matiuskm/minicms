<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function resetToken(User $user): string
{
    $token = null;
    Notification::fake();
    test()->post('/forgot-password', ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

test('reset request gives the same answer for known and unknown emails', function () {
    $user = User::factory()->create();

    $a = $this->post('/forgot-password', ['email' => $user->email]);
    $b = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    expect(session('status'))->toBe('If the account exists, a reset link has been sent.');
    $a->assertSessionHasNoErrors();
    $b->assertSessionHasNoErrors();
});

test('password is reset with a valid token, which clears lock and sessions', function () {
    $user = User::factory()->create();
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = resetToken($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    expect($user->fresh()->locked_until)->toBeNull();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('TC-F01-05 a reset token cannot be used twice', function () {
    $user = User::factory()->create();
    $token = resetToken($user);
    $payload = ['token' => $token, 'email' => $user->email, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'];

    $this->post('/reset-password', $payload)->assertSessionHasNoErrors();
    $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
});

test('TC-F01-05 an expired reset token is rejected', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    $this->travel(61)->minutes();

    $this->post('/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasErrors('email');
});

test('BR-01 weak passwords are rejected on reset', function () {
    $user = User::factory()->create();
    $token = resetToken($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});
