import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { StatusBadge } from '@/components/content/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/lib/media';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pages', href: '/admin/pages' }];

interface Row {
    id: number;
    title: string;
    slug: string;
    status: string;
    sort_order: number;
    deleted_at: string | null;
    parent: string | null;
    updated_at: string;
}

interface Props {
    pages: Paginated<Row>;
    counts: Record<string, number>;
    filters: { status: string; q: string };
}

const TABS: [string, string][] = [
    ['', 'All'],
    ['draft', 'Draft'],
    ['published', 'Published'],
    ['trashed', 'Trash'],
];

export default function Pages({ pages, counts, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const total = (counts.draft ?? 0) + (counts.published ?? 0);

    const go = (next: Record<string, string | null>) =>
        router.get(route('admin.pages.index'), { ...filters, ...next, page: undefined }, { preserveState: true });
    const search: FormEventHandler = (e) => {
        e.preventDefault();
        go({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pages" />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <nav className="flex gap-1" aria-label="Status">
                        {TABS.map(([t, label]) => (
                            <button
                                key={t || 'all'}
                                onClick={() => go({ status: t || null })}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm',
                                    (filters.status || '') === t ? 'bg-primary text-primary-foreground' : 'bg-card border',
                                )}
                            >
                                {label} <span className="opacity-70">{t === '' ? total : (counts[t] ?? 0)}</span>
                            </button>
                        ))}
                    </nav>
                    <Button asChild>
                        <Link href={route('admin.pages.create')}>
                            <Plus className="size-4" /> New page
                        </Link>
                    </Button>
                </div>

                <form onSubmit={search} className="flex gap-2">
                    <Input
                        aria-label="Search pages"
                        placeholder="Search by title"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        className="bg-card max-w-xs"
                    />
                    <Button variant="outline">Search</Button>
                </form>

                <div className="bg-card overflow-x-auto rounded-xl border shadow-xs">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Title</th>
                                <th className="p-3">Address</th>
                                <th className="p-3">Parent</th>
                                <th className="p-3">Order</th>
                                <th className="p-3">Status</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {pages.data.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="p-3 font-medium">
                                        <Link href={route('admin.pages.edit', p.id)} className="hover:underline">
                                            {p.title}
                                        </Link>
                                    </td>
                                    <td className="text-muted-foreground p-3">/{p.slug}</td>
                                    <td className="p-3">{p.parent ?? '—'}</td>
                                    <td className="p-3">{p.sort_order}</td>
                                    <td className="p-3">
                                        <StatusBadge status={p.deleted_at ? 'trashed' : p.status} />
                                    </td>
                                    <td className="p-3 text-right">
                                        {p.deleted_at && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(route('admin.pages.restore', p.id), {}, { preserveScroll: true })}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {pages.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        No pages yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <nav className="flex flex-wrap gap-1">
                    {pages.links.map((l, i) => (
                        <Button
                            key={i}
                            size="sm"
                            variant={l.active ? 'default' : 'outline'}
                            disabled={!l.url}
                            onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}
                        >
                            {l.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                        </Button>
                    ))}
                </nav>
            </div>
        </AppLayout>
    );
}
