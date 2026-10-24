<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Notifications\SecurityNotice;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function show(Request $request, Google2FA $google2fa): Response
    {
        $user = $request->user();
        $qr = $secret = null;

        if (! $user->hasTwoFactor()) {
            // Pending secret: not active until the first code is confirmed.
            if (! $user->two_factor_secret) {
                $user->forceFill(['two_factor_secret' => $google2fa->generateSecretKey(32)])->save();
            }
            $secret = $user->two_factor_secret;
            $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd)))
                ->writeString($google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret));
            $qr = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactor(),
            'required' => $user->isAdmin(),
            'qr' => $qr,
            'secret' => $secret,
            'recoveryCodes' => $request->session()->get('recoveryCodes'),
            'recoveryLeft' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function confirm(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'digits:6']]);

        if ($user->hasTwoFactor() || ! $user->two_factor_secret
            || ! $google2fa->verifyKey($user->two_factor_secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'The provided code is invalid.']);
        }

        // Consume the code so it cannot be replayed at the next login.
        Cache::add("2fa-used:{$user->id}:{$request->string('code')}", 1, 120);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        AuditLog::record('2fa.enabled', $user);

        return back()->with('recoveryCodes', $this->issueRecoveryCodes($request));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactor(), 404);

        AuditLog::record('2fa.recovery_regenerated');

        return back()->with('recoveryCodes', $this->issueRecoveryCodes($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();

        abort_if($user->isAdmin(), 403, 'Two-factor authentication is mandatory for admins.'); // BR-03

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        AuditLog::record('2fa.disabled', $user);
        $user->notify(new SecurityNotice('Two-factor authentication was turned off', ['Two-factor authentication is no longer required to sign in to your account.']));

        return back();
    }

    /** @return list<string> plain codes, shown once; only hashes are stored */
    private function issueRecoveryCodes(Request $request): array
    {
        $codes = collect(range(1, 8))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $request->user()->forceFill([
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();

        return $codes;
    }
}
