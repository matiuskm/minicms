<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\SecurityNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        // Password is correct but 2FA is pending: don't log in yet.
        // The local-only bypass (CMS_REQUIRE_ADMIN_2FA=false) also skips the challenge; it never applies in production.
        if ($user->hasTwoFactor() && EnforceSessionPolicy::twoFactorRequired()) {
            $request->session()->regenerate();
            $request->session()->put([
                'login.id' => $user->id,
                'login.remember' => $request->boolean('remember'),
                'login.at' => now()->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->finishLogin($request, $user, $request->boolean('remember'));
    }

    public static function finishLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);

        $user->forceFill(['last_login_at' => now()])->save();

        if (KnownDevice::seen($user, (string) $request->userAgent())) {
            AuditLog::record('login.new_device', $user, [], $user->id);
            $user->notify(new SecurityNotice('New sign-in to your account', [
                'Your account was just signed in to from a browser we have not seen before.',
                'When: '.now()->format('Y-m-d H:i').' UTC',
                'From: '.$request->ip().' — '.mb_substr((string) $request->userAgent(), 0, 120),
            ]));
        }
        AuditLog::record('login.success', $user, [], $user->id);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record('logout');
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
