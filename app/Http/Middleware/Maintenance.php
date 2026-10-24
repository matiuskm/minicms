<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Maintenance mode (FR-62): everyone sees a 503 except signed-in admins; the login screens stay reachable. */
class Maintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::get('maintenance_mode')
            || $request->user()?->isAdmin()
            || $request->is('login', 'logout', 'two-factor-challenge', 'forgot-password', 'reset-password/*', 'invite/*', 'invite', 'up')) {
            return $next($request);
        }

        abort(503, 'maintenance');
    }
}
