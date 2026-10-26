<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        return $this->pendingUser($request)
            ? Inertia::render('auth/two-factor-challenge')
            : redirect()->route('login');
    }

    public function store(Request $request, Google2FA $google2fa): BaseResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);

        $ok = $request->filled('recovery_code')
            ? $this->useRecoveryCode($user, trim($request->string('recovery_code')))
            : $this->validCode($user, $google2fa, trim($request->string('code')));

        if (! $ok) {
            AuditLog::record('2fa.failed', $user, [], $user->id);

            throw ValidationException::withMessages(['code' => 'The provided code is invalid.']);
        }

        return AuthenticatedSessionController::finishLogin($request, $user, (bool) $request->session()->get('login.remember'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = $request->session()->get('login.at', 0);

        // The password step is only valid for 5 minutes.
        if (! $id || now()->timestamp - $at > 300) {
            return null;
        }

        $user = User::find($id);

        return $user?->isActive() && $user->hasTwoFactor() ? $user : null;
    }

    private function validCode(User $user, Google2FA $google2fa, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code) || ! $google2fa->verifyKey($user->two_factor_secret, $code)) {
            return false;
        }

        // Replay guard: a TOTP code works once within its validity window.
        return Cache::add("2fa-used:{$user->id}:{$code}", 1, 120);
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (Hash::check(strtolower($code), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                AuditLog::record('2fa.recovery_used', $user, [], $user->id);

                return true;
            }
        }

        return false;
    }
}
