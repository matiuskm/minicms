<?php

namespace App\Http\Middleware;

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
        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            // One-shot message set by controllers via ->with('status', ...).
            'flash' => fn () => ['status' => $request->session()->get('status')],
            // Shown as a warning in the admin header while the local-only 2FA bypass is on.
            'twoFactorBypassed' => ! EnforceSessionPolicy::twoFactorRequired(),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'display_name', 'email', 'role'),
            ],
        ]);
    }
}
