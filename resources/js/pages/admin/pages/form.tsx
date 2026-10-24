import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, Eye, History } from 'lucide-react';
import { useState } from 'react';

import { PreviewDialog } from '@/components/content/preview-dialog';
import { RevisionsDialog } from '@/components/content/revisions-dialog';
import { StatusBadge } from '@/components/content/status-badge';
import { Tabs } from '@/components/content/tabs';
import { RichEditor } from '@/components/editor/rich-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

interface PageData {
    id: number;
    title: string;
    slug: string;
    body: string;
    meta_title: string | null;
    meta_description: string | null;
    status: string;
    parent_id: number | null;
    sort_order: number;
    version: number;
    trashed: boolean;
}

interface Props {
    page: PageData | null;
    parents: { id: number; title: string }[];
}

const selectClass = 'border-input bg-card h-9 w-full rounded-md border px-2 text-sm';

function PageForm({ page, parents }: Props) {
    const editable = !page?.trashed;
    const form = useForm({
        action: 'save',
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        body: page?.body ?? '',
        meta_title: page?.meta_title ?? '',
        meta_description: page?.meta_description ?? '',
        parent_id: page?.parent_id ? String(page.parent_id) : '',
        sort_order: String(page?.sort_order ?? 0),
        version: page?.version ?? 0,
    });
    const [preview, setPreview] = useState(false);
    const [revisions, setRevisions] = useState(false);
    const errors = form.errors as Record<string, string>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pages', href: '/admin/pages' },
        { title: page ? 'Edit' : 'New', href: page ? `/admin/pages/${page.id}` : '/admin/pages/create' },
    ];

    const send = (action: string) => {
        form.transform((d) => ({ ...d, action, parent_id: d.parent_id || null, sort_order: d.sort_order === '' ? 0 : Number(d.sort_order) }));
        const opts = { preserveScroll: true };
        if (page) form.put(route('admin.pages.update', page.id), opts);
        else form.post(route('admin.pages.store'), opts);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={page ? `Edit: ${page.title}` : 'New page'} />

            <div className="space-y-4 p-4">
                {errors.version && (
                    <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        This page was changed elsewhere.{' '}
                        <button className="underline" onClick={() => router.reload()}>
                            Reload
                        </button>{' '}
                        to see the latest version.
                    </div>
                )}
                {errors.page && <p className="text-sm text-red-600">{errors.page}</p>}

                <div className="flex flex-wrap items-center gap-3">
                    <div className="min-w-64 flex-1">
                        <Input
                            aria-label="Title"
                            placeholder="Page title"
                            className="bg-card h-11 text-lg font-semibold"
                            disabled={!editable}
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                        />
                        <InputError message={errors.title} />
                    </div>
                    <Button variant="outline" onClick={() => setPreview(true)}>
                        <Eye className="size-4" /> Preview
                    </Button>
                    {editable && (
                        <div className="flex">
                            <Button className="rounded-r-none" disabled={form.processing} onClick={() => send('save')}>
                                Save
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        aria-label="More save options"
                                        className="rounded-l-none border-l border-white/30 px-2"
                                        disabled={form.processing}
                                    >
                                        <ChevronDown className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem onClick={() => send('publish')}>Publish</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
                    <Tabs
                        tabs={[
                            {
                                id: 'content',
                                label: 'Content',
                                content: (
                                    <>
                                        <RichEditor
                                            value={form.data.body}
                                            onChange={(html) => editable && form.setData('body', html)}
                                            canEditSource
                                        />
                                        <InputError message={errors.body} />
                                    </>
                                ),
                            },
                            {
                                id: 'meta',
                                label: 'Meta',
                                content: (
                                    <div className="grid gap-2">
                                        <Label htmlFor="slug">Slug</Label>
                                        <Input
                                            id="slug"
                                            value={form.data.slug}
                                            placeholder="generated from the title"
                                            disabled={!editable}
                                            onChange={(e) => form.setData('slug', e.target.value)}
                                        />
                                        <InputError message={errors.slug} />
                                    </div>
                                ),
                            },
                            {
                                id: 'seo',
                                label: 'SEO',
                                content: (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="meta_title">Meta title ({form.data.meta_title.length}/70)</Label>
                                            <Input
                                                id="meta_title"
                                                disabled={!editable}
                                                value={form.data.meta_title}
                                                onChange={(e) => form.setData('meta_title', e.target.value)}
                                                placeholder={form.data.title}
                                            />
                                            <InputError message={errors.meta_title} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="meta_description">Meta description ({form.data.meta_description.length}/160)</Label>
                                            <textarea
                                                id="meta_description"
                                                rows={3}
                                                disabled={!editable}
                                                className="border-input bg-card rounded-md border p-2 text-sm"
                                                value={form.data.meta_description}
                                                onChange={(e) => form.setData('meta_description', e.target.value)}
                                            />
                                            <InputError message={errors.meta_description} />
                                        </div>
                                    </>
                                ),
                            },
                        ]}
                    />

                    <aside className="space-y-4">
                        <div className="bg-card space-y-4 rounded-xl border p-4 shadow-xs">
                            <div>
                                <Label className="text-muted-foreground text-xs">Status</Label>
                                <div className="pt-1">
                                    <StatusBadge status={page?.trashed ? 'trashed' : (page?.status ?? 'draft')} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="parent" className="text-muted-foreground text-xs">
                                    Parent page
                                </Label>
                                <select
                                    id="parent"
                                    className={selectClass}
                                    disabled={!editable}
                                    value={form.data.parent_id}
                                    onChange={(e) => form.setData('parent_id', e.target.value)}
                                >
                                    <option value="">No parent</option>
                                    {parents.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.title}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.parent_id} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="sort_order" className="text-muted-foreground text-xs">
                                    Menu order
                                </Label>
                                <Input
                                    id="sort_order"
                                    type="number"
                                    min={0}
                                    disabled={!editable}
                                    value={form.data.sort_order}
                                    onChange={(e) => form.setData('sort_order', e.target.value)}
                                />
                                <InputError message={errors.sort_order} />
                            </div>
                        </div>

                        {page && (
                            <div className="bg-card flex flex-col gap-2 rounded-xl border p-4 shadow-xs">
                                <Button variant="outline" size="sm" onClick={() => setRevisions(true)}>
                                    <History className="size-4" /> Revisions
                                </Button>
                                {page.status === 'published' && !page.trashed && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.post(route('admin.pages.unpublish', page.id), {}, { preserveScroll: true })}
                                    >
                                        Unpublish
                                    </Button>
                                )}
                                {page.trashed ? (
                                    <>
                                        <Button variant="outline" size="sm" onClick={() => router.post(route('admin.pages.restore', page.id))}>
                                            Restore from trash
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() =>
                                                confirm('Delete permanently? This cannot be undone.') &&
                                                router.delete(route('admin.pages.purge', page.id))
                                            }
                                        >
                                            Delete permanently
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={() =>
                                            confirm('Move this page to the trash?') && router.delete(route('admin.pages.destroy', page.id))
                                        }
                                    >
                                        Move to trash
                                    </Button>
                                )}
                            </div>
                        )}
                    </aside>
                </div>
            </div>

            <PreviewDialog open={preview} onClose={() => setPreview(false)} title={form.data.title} body={form.data.body} />
            {page && (
                <RevisionsDialog
                    open={revisions}
                    onClose={() => setRevisions(false)}
                    listUrl={route('admin.pages.revisions', page.id)}
                    restoreUrl={(rev) => route('admin.pages.revisions.restore', [page.id, rev])}
                />
            )}
        </AppLayout>
    );
}

export default function Form(props: Props) {
    return <PageForm key={`${props.page?.id ?? 'new'}-${props.page?.version ?? 0}`} {...props} />;
}
