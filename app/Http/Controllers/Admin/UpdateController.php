<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Updates\Updater;
use App\Support\Updates\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** One-click updates (F-15). Admin only (route group). Requests an update; it never applies one itself. */
class UpdateController extends Controller
{
    public function index(): Response
    {
        $latest = Updater::latest();

        return Inertia::render('admin/updates', [
            'current' => Updater::current(),
            'latest' => $latest,
            'available' => (bool) Updater::available(),
            'enabled' => Updater::enabled(),
            'status' => Updater::status(),
        ]);
    }

    public function check(): RedirectResponse
    {
        $found = Updater::available(refresh: true);

        return back()->with('status', $found ? "Version {$found['version']} is available." : 'You are running the latest version.');
    }

    public function install(Request $request): RedirectResponse
    {
        $version = (string) $request->validate(['version' => ['required', 'string', 'regex:'.Version::PATTERN]])['version'];

        if (! Updater::canInstall($version)) {
            throw ValidationException::withMessages(['version' => Updater::enabled()
                ? (Updater::busy() ? 'An update is already in progress.' : 'That version is not available to install.')
                : 'One-click updates are not enabled on this server. See the instructions on this page.']);
        }

        Updater::request($version, $request->user());
        AuditLog::record('update.requested', null, ['from' => Updater::current(), 'to' => $version]);

        return back()->with('status', 'Update requested. It starts within a minute; this page shows the progress.');
    }

    /** Polled by the page while an update runs. */
    public function status(): JsonResponse
    {
        return response()->json(Updater::status());
    }
}
