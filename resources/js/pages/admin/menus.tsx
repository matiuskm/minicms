import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Menus', href: '/admin/menus' }];

type Item = {
    label: string;
    type: 'page' | 'category' | 'url';
    page_id: number | null;
    category_id: number | null;
    url: string | null;
};

interface Props {
    menus: { header: Item[]; footer: Item[] };
    pages: { id: number; title: string }[];
    categories: { id: number; name: string }[];
}

const selectClass = 'border-input bg-card h-9 rounded-md border px-2 text-sm';

function MenuEditor({
    location,
    initial,
    pages,
    categories,
}: {
    location: 'header' | 'footer';
    initial: Item[];
    pages: Props['pages'];
    categories: Props['categories'];
}) {
    const [items, setItems] = useState<Item[]>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const patch = (i: number, change: Partial<Item>) => setItems(items.map((it, n) => (n === i ? { ...it, ...change } : it)));
    const move = (i: number, d: -1 | 1) => {
        const next = [...items];
        [next[i], next[i + d]] = [next[i + d], next[i]];
        setItems(next);
    };

    const save = () => {
        setBusy(true);
        router.put(
            route('admin.menus.update', location),
            { items },
            { preserveScroll: true, onError: setErrors, onSuccess: () => setErrors({}), onFinish: () => setBusy(false) },
        );
    };

    return (
        <section className="bg-card space-y-3 rounded-xl border p-5 shadow-xs">
            <h2 className="font-semibold capitalize">{location} menu</h2>
            {items.length === 0 && <p className="text-muted-foreground text-sm">No links yet.</p>}
            {items.map((it, i) => (
                <div key={i} className="grid items-start gap-2 md:grid-cols-[1fr_8rem_1fr_auto]">
                    <div>
                        <Input
                            aria-label="Label"
                            placeholder="Label"
                            value={it.label}
                            maxLength={60}
                            onChange={(e) => patch(i, { label: e.target.value })}
                        />
                        <InputError message={errors[`items.${i}.label`]} />
                    </div>
                    <select
                        aria-label="Type"
                        className={selectClass}
                        value={it.type}
                        onChange={(e) => patch(i, { type: e.target.value as Item['type'], page_id: null, category_id: null, url: null })}
                    >
                        <option value="page">Page</option>
                        <option value="category">Category</option>
                        <option value="url">Link</option>
                    </select>
                    <div>
                        {it.type === 'page' && (
                            <select
                                aria-label="Page"
                                className={`${selectClass} w-full`}
                                value={it.page_id ?? ''}
                                onChange={(e) => patch(i, { page_id: Number(e.target.value) || null })}
                            >
                                <option value="">Choose a published page</option>
                                {pages.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.title}
                                    </option>
                                ))}
                            </select>
                        )}
                        {it.type === 'category' && (
                            <select
                                aria-label="Category"
                                className={`${selectClass} w-full`}
                                value={it.category_id ?? ''}
                                onChange={(e) => patch(i, { category_id: Number(e.target.value) || null })}
                            >
                                <option value="">Choose a category</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {it.type === 'url' && (
                            <Input
                                aria-label="Link"
                                placeholder="https://… or /path"
                                value={it.url ?? ''}
                                onChange={(e) => patch(i, { url: e.target.value })}
                            />
                        )}
                        <InputError message={errors[`items.${i}.page_id`] ?? errors[`items.${i}.category_id`] ?? errors[`items.${i}.url`]} />
                    </div>
                    <div className="flex gap-1">
                        <Button type="button" size="icon" variant="ghost" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                            <ArrowUp className="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label="Move down"
                            disabled={i === items.length - 1}
                            onClick={() => move(i, 1)}
                        >
                            <ArrowDown className="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label="Remove"
                            onClick={() => setItems(items.filter((_, n) => n !== i))}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>
            ))}
            <InputError message={errors.items} />
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={items.length >= 12}
                    onClick={() => setItems([...items, { label: '', type: 'page', page_id: null, category_id: null, url: null }])}
                >
                    <Plus className="size-4" /> Add link
                </Button>
                <Button type="button" size="sm" disabled={busy} onClick={save}>
                    Save {location} menu
                </Button>
            </div>
        </section>
    );
}

export default function Menus({ menus, pages, categories }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Menus" />
            <div className="max-w-4xl space-y-6 p-4">
                <p className="text-muted-foreground text-sm">
                    Links appear in the order shown. Pages that are not published are skipped on the site.
                </p>
                <MenuEditor location="header" initial={menus.header} pages={pages} categories={categories} />
                <MenuEditor location="footer" initial={menus.footer} pages={pages} categories={categories} />
            </div>
        </AppLayout>
    );
}
