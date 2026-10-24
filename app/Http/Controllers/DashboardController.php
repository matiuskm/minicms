<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Role-scoped overview (FR-72): admins see the whole site and its health, writers see their own posts. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $counts = Post::query()->visibleTo($user)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $queue = Post::query()->visibleTo($user)->where('status', $user->isAdmin() ? 'pending_review' : 'rejected')
            ->select(['id', 'author_id', 'title', 'review_note', 'updated_at'])->with('author:id,name')
            ->latest('updated_at')->limit(5)->get()
            ->map(fn (Post $p) => ['id' => $p->id, 'title' => $p->title, 'author' => $p->author->name, 'note' => $p->review_note, 'updated_at' => $p->updated_at->toIso8601String()]);

        $admin = [];
        if ($user->isAdmin()) {
            $integrity = Setting::get('integrity_last');
            $backup = Setting::get('backup_last');

            $admin = [
                'activity' => AuditLog::with('actor:id,name')->latest('id')->limit(8)->get()
                    ->map(fn (AuditLog $l) => ['id' => $l->id, 'action' => $l->action, 'actor' => $l->actor?->name, 'at' => $l->created_at->toIso8601String()]),
                'failedLogins24h' => AuditLog::where('action', 'login.failed')->where('created_at', '>=', now()->subDay())->count(),
                'integrity' => $integrity ? ['at' => $integrity['at'], 'mismatches' => count($integrity['mismatches'])] : null,
                'backup' => $backup ? ['at' => $backup['at'], 'ok' => $backup['ok']] : null,
                'lockdown' => (bool) Setting::get('lockdown'),
            ];
        }

        return Inertia::render('dashboard', ['counts' => $counts, 'queue' => $queue, ...$admin]);
    }
}
