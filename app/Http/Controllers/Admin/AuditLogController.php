<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = $this->filtered($request)->with('actor:id,name')
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (AuditLog $l) => $this->row($l));

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $logs,
            'filters' => $request->only('actor', 'action', 'from', 'to'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('actor:id,name')->latest('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'actor', 'action', 'object', 'ip']);
            foreach ($query->lazyById(500) as $log) {
                $r = $this->row($log);
                fputcsv($out, [$r['created_at'], $r['actor'], $r['action'], $r['object'], $r['ip']]);
            }
            fclose($out);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        return AuditLog::query()
            ->when($request->filled('actor'), fn ($q) => $q->where('actor_id', (int) $request->query('actor')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->query('action').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));
    }

    /** @return array<string, mixed> */
    private function row(AuditLog $l): array
    {
        return [
            'id' => $l->id,
            'created_at' => $l->created_at->toDateTimeString(),
            'actor' => $l->actor?->name,
            'action' => $l->action,
            'object' => $l->object_type ? "{$l->object_type} #{$l->object_id}" : null,
            'ip' => $l->ipText(),
        ];
    }
}
