import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Categories', href: '/admin/categories' }];

interface Category {
    id: number;
    parent_id: number | null;
    parent: string | null;
    name: string;
    slug: string;
    description: string | null;
    is_system: boolean;
    hide_from_blog: boolean;
    list_layout: boolean;
    url_prefix: string | null;
    posts_count: number;
}

const selectClass = 'border-input h-9 rounded-md border bg-transparent px-2 text-sm';

function Row({ category, parents }: { category: Category; parents: Category[] }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: category.name,
        slug: category.slug,
        description: category.description ?? '',
        parent_id: category.parent_id ? String(category.parent_id) : '',
        hide_from_blog: category.hide_from_blog,
        list_layout: category.list_layout,
        url_prefix: category.url_prefix ?? '',
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, parent_id: d.parent_id || null, url_prefix: d.url_prefix || null }));
        form.put(route('admin.categories.update', category.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    if (editing) {
        return (
            <tr className="border-t align-top">
                <td className="p-3" colSpan={5}>
                    <form onSubmit={save} className="grid gap-2 md:grid-cols-4">
                        <div>
                            <Input aria-label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={form.errors.name} />
                        </div>
                        <div>
                            <Input aria-label="Slug" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} />
                            <InputError message={form.errors.slug} />
                        </div>
                        <div>
                            <select
                                aria-label="Parent"
                                className={selectClass}
                                value={form.data.parent_id}
                                onChange={(e) => form.setData('parent_id', e.target.value)}
                            >
                                <option value="">No parent</option>
                                {parents
                                    .filter((p) => p.id !== category.id)
                                    .map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.parent_id} />
                        </div>
                        <Input aria-label="Description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        <div>
                            <Input
                                aria-label="URL path"
                                placeholder="URL path (e.g. works)"
                                value={form.data.url_prefix}
                                onChange={(e) => form.setData('url_prefix', e.target.value)}
                            />
                            <InputError message={form.errors.url_prefix} />
                        </div>
                        <div className="grid gap-1 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.hide_from_blog}
                                    onChange={(e) => form.setData('hide_from_blog', e.target.checked)}
                                />
                                Hide from blog
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.list_layout}
                                    onChange={(e) => form.setData('list_layout', e.target.checked)}
                                />
                                List layout
                            </label>
                        </div>
                        <div className="flex gap-2">
                            <Button size="sm" disabled={form.processing}>
                                Save
                            </Button>
                            <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </td>
            </tr>
        );
    }

    return (
        <tr className="border-t">
            <td className="p-3 font-medium">
                {category.parent && <span className="text-muted-foreground">{category.parent} / </span>}
                {category.name} {category.is_system && <Badge variant="secondary">system</Badge>}{' '}
                {category.hide_from_blog && <Badge variant="outline">hidden from blog</Badge>}{' '}
                {category.url_prefix && <Badge variant="outline">/{category.url_prefix}</Badge>}
            </td>
            <td className="text-muted-foreground p-3">{category.slug}</td>
            <td className="text-muted-foreground p-3">{category.description}</td>
            <td className="p-3">{category.posts_count}</td>
            <td className="space-x-2 p-3 text-right">
                {!category.is_system && (
                    <>
                        <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() =>
                                confirm(`Delete "${category.name}"?`) &&
                                router.delete(route('admin.categories.destroy', category.id), { preserveScroll: true })
                            }
                        >
                            Delete
                        </Button>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function Categories({ categories }: { categories: Category[] }) {
    const errors = usePage().props.errors as Record<string, string>;
    const create = useForm({
        name: '',
        description: '',
        parent_id: '',
        hide_from_blog: false as boolean,
        list_layout: false as boolean,
        url_prefix: '',
    });
    const topLevel = categories.filter((c) => c.parent_id === null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        create.transform((d) => ({ ...d, parent_id: d.parent_id || null, url_prefix: d.url_prefix || null }));
        create.post(route('admin.categories.store'), { preserveScroll: true, onSuccess: () => create.reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categories" />

            <div className="space-y-6 p-4">
                {errors?.category && <p className="text-sm text-red-600">{errors.category}</p>}

                <form onSubmit={submit} className="bg-card grid items-end gap-3 rounded-xl border p-4 shadow-xs md:grid-cols-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} />
                        <InputError message={create.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="parent">Parent</Label>
                        <select
                            id="parent"
                            className={selectClass}
                            value={create.data.parent_id}
                            onChange={(e) => create.setData('parent_id', e.target.value)}
                        >
                            <option value="">No parent</option>
                            {topLevel.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={create.errors.parent_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <Input id="description" value={create.data.description} onChange={(e) => create.setData('description', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="url_prefix">URL path</Label>
                        <Input
                            id="url_prefix"
                            placeholder="e.g. works"
                            value={create.data.url_prefix}
                            onChange={(e) => create.setData('url_prefix', e.target.value)}
                        />
                        <InputError message={create.errors.url_prefix} />
                    </div>
                    <div className="grid gap-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={create.data.hide_from_blog}
                                onChange={(e) => create.setData('hide_from_blog', e.target.checked)}
                            />
                            Hide from blog
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={create.data.list_layout}
                                onChange={(e) => create.setData('list_layout', e.target.checked)}
                            />
                            List layout
                        </label>
                    </div>
                    <Button disabled={create.processing}>Add category</Button>
                </form>

                <div className="bg-card overflow-x-auto rounded-xl border shadow-xs">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Slug</th>
                                <th className="p-3">Description</th>
                                <th className="p-3">Posts</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((c) => (
                                <Row key={`${c.id}-${c.name}-${c.slug}-${c.parent_id}`} category={c} parents={topLevel} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
