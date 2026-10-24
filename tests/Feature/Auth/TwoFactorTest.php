<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

const SECRET = 'JBSWY3DPEHPK3PXP';

function otp(): string
{
    return (new Google2FA)->getCurrentOtp(SECRET);
}

test('TC-F01-04 admin without 2FA is forced to set it up', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/settings/two-factor');
    $this->actingAs($admin)->get('/admin/users')->assertRedirect('/settings/two-factor');
    $this->actingAs($admin)->get('/settings/two-factor')->assertOk();
});

test('2FA setup confirms with a valid code and issues recovery codes', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/settings/two-factor')->assertOk();
    $admin->forceFill(['two_factor_secret' => SECRET])->save();

    $this->actingAs($admin)->post('/settings/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->actingAs($admin)->post('/settings/two-factor', ['code' => otp()])
        ->assertSessionHas('recoveryCodes', fn ($c) => count($c) === 8);

    expect($admin->fresh()->hasTwoFactor())->toBeTrue();
    $this->actingAs($admin)->get('/dashboard')->assertOk();
});

test('login with 2FA needs a valid code before the session is authenticated', function () {
    $user = User::factory()->withTwoFactor(SECRET)->create();

    $this->post('/login', ['email' => $user->email, 'password' => PW])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => otp()])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('a TOTP code cannot be replayed', function () {
    $user = User::factory()->withTwoFactor(SECRET)->create();
    $code = otp();

    $this->post('/login', ['email' => $user->email, 'password' => PW]);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => PW]);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a recovery code works exactly once', function () {
    $user = User::factory()->withTwoFactor(SECRET)->create();
    $user->forceFill(['two_factor_recovery_codes' => [Hash::make('abcde-fghij')]])->save();

    $this->post('/login', ['email' => $user->email, 'password' => PW]);
    $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-fghij'])->assertRedirect('/dashboard');
    expect($user->fresh()->two_factor_recovery_codes)->toBe([]);
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => PW]);
    $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-fghij'])->assertSessionHasErrors('code');
});

test('the challenge page requires a password step first', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
});

test('BR-03 admins cannot disable 2FA but writers can', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($admin)->delete('/settings/two-factor', ['current_password' => PW])->assertForbidden();

    $writer = User::factory()->withTwoFactor()->create();
    $this->actingAs($writer)->delete('/settings/two-factor', ['current_password' => PW])->assertSessionHasNoErrors();
    expect($writer->fresh()->hasTwoFactor())->toBeFalse();
});

test('local-only bypass: with the switch off an admin without 2FA can work, and the UI says so', function () {
    config(['cms.require_admin_2fa' => false]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('twoFactorBypassed', true));
    $this->actingAs($admin)->get('/admin/users')->assertOk();
});

test('the bypass switch is ignored in production', function () {
    config(['cms.require_admin_2fa' => false]);
    app()->detectEnvironment(fn () => 'production');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/settings/two-factor');
    $this->actingAs($admin)->get('/settings/two-factor')->assertOk()->assertInertia(fn ($p) => $p->where('twoFactorBypassed', false));
});

test('by default the requirement is on and nothing is flagged as bypassed', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn ($p) => $p->where('twoFactorBypassed', false));
});
