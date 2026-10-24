<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Support\Backup;
use App\Support\IntegrityChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Security centre (FR-70, FR-71): lockdown, content integrity, backup status. Admin only (route group). */
class SecurityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/security', [
            'lockdown' => (bool) Setting::get('lockdown'),
            'integrity' => Setting::get('integrity_last'),
            'backup' => Setting::get('backup_last'),
            'backupSets' => array_slice(rescue(fn () => Backup::sets(), [], false), 0, 5),
            'failedLogins24h' => AuditLog::where('action', 'login.failed')->where('created_at', '>=', now()->subDay())->count(),
            'lockouts24h' => AuditLog::where('action', 'login.locked')->where('created_at', '>=', now()->subDay())->count(),
        ]);
    }

    /** Closes sign-in for everyone but admins and signs every writer out (incident response, FL-06). */
    public function lockdown(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        Setting::put(['lockdown' => $enabled]);

        if ($enabled) {
            DB::table('sessions')->whereIn('user_id', fn ($q) => $q->select('id')->from('users')->where('role', '!=', 'admin'))->delete();
        }
        AuditLog::record($enabled ? 'lockdown.enabled' : 'lockdown.disabled');

        return back()->with('status', $enabled ? 'Lockdown on: only admins can sign in, writers were signed out.' : 'Lockdown lifted.');
    }

    public function check(): RedirectResponse
    {
        $r = IntegrityChecker::run();

        return back()->with('status', "Checked {$r['checked']} item(s): ".(count($r['mismatches']) ?: 'no').' mismatch(es).');
    }

    /** The reviewer confirms the current content is legitimate; it becomes the new baseline. */
    public function reseal(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['post', 'page'])], 'id' => ['required', 'integer']]);
        abort_unless(($data['type'] === 'page' ? Page::withTrashed() : Post::withTrashed())->whereKey($data['id'])->exists(), 404);

        IntegrityChecker::reseal($data['type'], $data['id']);
        IntegrityChecker::run();

        return back()->with('status', 'Content confirmed as legitimate.');
    }
}
