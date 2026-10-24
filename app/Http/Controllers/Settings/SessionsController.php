<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SessionsController extends Controller
{
    public function index(Request $request): Response
    {
        $current = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'ip' => $s->ip_address,
                'agent' => mb_substr((string) $s->user_agent, 0, 120),
                'last_active' => date('c', $s->last_activity),
                'current' => $s->id === $current,
            ]);

        return Inertia::render('settings/sessions', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        abort_if($id === $request->session()->getId(), 422, 'Use logout for the current session.');

        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();
        AuditLog::record('session.revoked');

        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->user()->endSessions($request->session()->getId());
        AuditLog::record('session.revoked_all');

        return back();
    }
}
