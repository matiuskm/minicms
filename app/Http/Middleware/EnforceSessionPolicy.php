<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Idle timeout (BR-04), disabled-account kick, and mandatory admin 2FA (BR-03). */
class EnforceSessionPolicy
{
    /** Always true in production, whatever the environment file says. */
    public static function twoFactorRequired(): bool
    {
        return app()->isProduction() || config('cms.require_admin_2fa');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $idle = config("cms.idle_minutes.{$user->role}", 30) * 60;
        $last = $request->session()->get('last_seen', now()->timestamp);

        if (! $user->isActive() || now()->timestamp - $last > $idle) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $request->session()->put('last_seen', now()->timestamp);

        if ($user->isAdmin() && ! $user->hasTwoFactor() && self::twoFactorRequired()
            && ! $request->routeIs('two-factor.*', 'logout')) {
            return redirect()->route('two-factor.show');
        }

        return $next($request);
    }
}
