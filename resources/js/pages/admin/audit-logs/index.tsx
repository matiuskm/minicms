import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/admin/audit-logs' }];

interface Log {
    id: number;
    created_at: string;
    actor: string | null;
    action: string;
    object: string | null;
    ip: string | null;
}

interface Props {
    logs: { data: Log[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { action?: string; from?: string; to?: string };
}

export default function AuditLogs({ logs, filters }: Props) {
    const [f, setF] = useState({ action: filters.action ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.audit-logs.index'), query, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />

            <div className="space-y-6 p-4">
                <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                    <Input placeholder="Action (e.g. login)" value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })} className="max-w-48" />
                    <Input type="date" aria-label="From" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} className="max-w-40" />
                    <Input type="date" aria-label="To" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} className="max-w-40" />
                    <Button>Filter</Button>
                    <Button type="button" variant="outline" asChild>
                        <a href={route('admin.audit-logs.export', query)}>Export CSV</a>
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Time</th>
                                <th className="p-3">Actor</th>
                                <th className="p-3">Action</th>
                                <th className="p-3">Object</th>
                                <th className="p-3">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((l) => (
                                <tr key={l.id} className="border-t">
                                    <td className="p-3 whitespace-nowrap">{l.created_at}</td>
                                    <td className="p-3">{l.actor ?? '—'}</td>
                                    <td className="p-3 font-mono">{l.action}</td>
                                    <td className="p-3">{l.object ?? '—'}</td>
                                    <td className="p-3">{l.ip ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav className="flex flex-wrap gap-1">
                    {logs.links.map((l, i) => (
                        <Button key={i} size="sm" variant={l.active ? 'default' : 'outline'} disabled={!l.url} onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}>
                            {l.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                        </Button>
                    ))}
                </nav>
            </div>
        </AppLayout>
    );
}
