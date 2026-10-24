import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { StatusBadge } from '@/components/content/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { STATUS_LABELS, type Option } from '@/lib/content';
import { type Paginated } from '@/lib/media';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Posts', href: '/admin/posts' }];

interface Row {
    id: number;
    title: string;
    status: string;
    deleted_at: string | null;
    author: string;
    category: string;
    published_at: string | null;
    updated_at: string;
}

interface Props {
    posts: Paginated<Row>;
    counts: Record<string, number>;
    filters: { status: string; q: string; category: string | null; author: string | null };
    categories: Option[];
    authors: Option[];
}

const TABS = ['', 'draft', 'pending_review', 'rejected', 'scheduled', 'published', 'archived', 'trashed'];
const selectClass = 'border-input bg-card h-9 rounded-md border px-2 text-sm';

export default function Posts({ posts, counts, filters, categories, authors }: Props) {
    const [q, setQ] = useState(filters.q);
    const total = Object.entries(counts).reduce((n, [k, v]) => (k === 'trashed' ? n : n + v), 0);

    const go = (next: Record<string, string | null>) =>
        router.get(route('admin.posts.index'), { ...filters, ...next, page: undefined }, { preserveState: true });

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        go({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Posts" />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <nav className="flex flex-wrap gap-1" aria-label="Status">
                        {TABS.map((t) => {
                            const n = t === '' ? total : (counts[t] ?? 0);
                            if (t !== '' && n === 0 && filters.status !== t) return null;
                            return (
                                <button
                                    key={t || 'all'}
                                    onClick={() => go({ status: t || null })}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm',
                                        (filters.status || '') === t ? 'bg-primary text-primary-foreground' : 'bg-card border',
                                    )}
                                >
                                    {t === '' ? 'All' : STATUS_LABELS[t]} <span className="opacity-70">{n}</span>
                                </button>
                            );
                        })}
                    </nav>
                    <Button asChild>
                        <Link href={route('admin.posts.create')}>
                            <Plus className="size-4" /> New post
                        </Link>
                    </Button>
                </div>

                <form onSubmit={search} className="flex flex-wrap gap-2">
                    <Input
                        aria-label="Search posts"
                        placeholder="Search by title"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        className="bg-card max-w-xs"
                    />
                    <select
                        aria-label="Category"
                        className={selectClass}
                        value={filters.category ?? ''}
                        onChange={(e) => go({ category: e.target.value || null })}
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    {authors.length > 0 && (
                        <select
                            aria-label="Author"
                            className={selectClass}
                            value={filters.author ?? ''}
                            onChange={(e) => go({ author: e.target.value || null })}
                        >
                            <option value="">All authors</option>
                            {authors.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <Button variant="outline">Search</Button>
                </form>

                <div className="bg-card overflow-x-auto rounded-xl border shadow-xs">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Title</th>
                                <th className="p-3">Author</th>
                                <th className="p-3">Category</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Updated</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {posts.data.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="p-3 font-medium">
                                        <Link href={route('admin.posts.edit', p.id)} className="hover:underline">
                                            {p.title}
                                        </Link>
                                    </td>
                                    <td className="p-3">{p.author}</td>
                                    <td className="p-3">{p.category}</td>
                                    <td className="p-3">
                                        <StatusBadge status={p.deleted_at ? 'trashed' : p.status} />
                                    </td>
                                    <td className="text-muted-foreground p-3 whitespace-nowrap">{new Date(p.updated_at).toLocaleString()}</td>
                                    <td className="p-3 text-right">
                                        {p.deleted_at && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(route('admin.posts.restore', p.id), {}, { preserveScroll: true })}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {posts.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        Nothing here yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <nav className="flex flex-wrap gap-1">
                    {posts.links.map((l, i) => (
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
