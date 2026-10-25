<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PublicController;
use App\Models\Setting;
use App\Support\Updates\Updater;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        // Public pages put their props into the HTML source: they only learn *whether* someone is signed in (for the
        // "Dashboard" link), never who, and nothing about updates or the 2FA bypass.
        $public = $request->route()?->getControllerClass() === PublicController::class;

        return [
            ...parent::share($request),
            // The site name from Site settings (APP_NAME is only the fallback); admin sidebar and page titles use it too.
            'name' => Setting::get('site_name') ?: config('app.name'),
            // One-shot message set by controllers via ->with('status', ...).
            'flash' => fn () => ['status' => $request->session()->get('status')],
            'auth' => ['user' => $public ? ($user ? ['id' => $user->id] : null) : $user?->only('id', 'name', 'display_name', 'email', 'role')],
            ...($public ? [] : [
                // Shown as a warning in the admin header while the local-only 2FA bypass is on.
                'twoFactorBypassed' => ! EnforceSessionPolicy::twoFactorRequired(),
                // Admins see a hint when a newer release is already known (cache only, never a network call here).
                'updateAvailable' => fn () => $user?->isAdmin() ? (Updater::cachedAvailable()['version'] ?? null) : null,
            ]),
        ];
    }
}
