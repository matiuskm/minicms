import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/lib/media';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tags', href: '/admin/tags' }];

interface Tag {
    id: number;
    name: string;
    slug: string;
}

function Row({ tag }: { tag: Tag }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: tag.name });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('admin.tags.update', tag.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <tr className="border-t">
            <td className="p-3">
                {editing ? (
                    <form onSubmit={save} className="flex items-start gap-2">
                        <div>
                            <Input aria-label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="h-8" />
                            <InputError message={form.errors.name} />
                        </div>
                        <Button size="sm">Save</Button>
                        <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                            Cancel
                        </Button>
                    </form>
                ) : (
                    <span className="font-medium">{tag.name}</span>
                )}
            </td>
            <td className="text-muted-foreground p-3">{tag.slug}</td>
            <td className="space-x-2 p-3 text-right">
                {!editing && (
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        Rename
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="destructive"
                    onClick={() =>
                        confirm(`Delete tag "${tag.name}"?`) && router.delete(route('admin.tags.destroy', tag.id), { preserveScroll: true })
                    }
                >
                    Delete
                </Button>
            </td>
        </tr>
    );
}

export default function Tags({ tags, filters }: { tags: Paginated<Tag>; filters: { q: string } }) {
    const create = useForm({ name: '' });
    const [q, setQ] = useState(filters.q);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        create.post(route('admin.tags.store'), { preserveScroll: true, onSuccess: () => create.reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tags" />

            <div className="space-y-6 p-4">
                <form onSubmit={submit} className="bg-card flex items-start gap-3 rounded-xl border p-4 shadow-xs">
                    <div className="grid gap-1">
                        <Input
                            aria-label="New tag"
                            placeholder="New tag"
                            value={create.data.name}
                            onChange={(e) => create.setData('name', e.target.value)}
                        />
                        <InputError message={create.errors.name} />
                    </div>
                    <Button disabled={create.processing}>Add tag</Button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(route('admin.tags.index'), { q }, { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <Input placeholder="Search tags" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" />
                    <Button variant="outline">Search</Button>
                </form>

                <div className="bg-card overflow-x-auto rounded-xl border shadow-xs">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Slug</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {tags.data.map((t) => (
                                <Row key={`${t.id}-${t.name}`} tag={t} />
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav className="flex flex-wrap gap-1">
                    {tags.links.map((l, i) => (
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
