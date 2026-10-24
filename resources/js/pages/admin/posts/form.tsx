import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, Eye, History, ImageIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PreviewDialog } from '@/components/content/preview-dialog';
import { RevisionsDialog } from '@/components/content/revisions-dialog';
import { StatusBadge } from '@/components/content/status-badge';
import { Tabs } from '@/components/content/tabs';
import { TagInput } from '@/components/content/tag-input';
import { MediaPicker } from '@/components/editor/media-picker';
import { RichEditor } from '@/components/editor/rich-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { fromLocalInput, postJson, toLocalInput, type Option } from '@/lib/content';
import { type MediaItem } from '@/lib/media';
import { type BreadcrumbItem } from '@/types';

interface PostData {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    body: string;
    meta_title: string | null;
    meta_description: string | null;
    status: string;
    review_note: string | null;
    category_id: number;
    version: number;
    published_at: string | null;
    author: string;
    tags: Option[];
    featured: MediaItem | null;
    trashed: boolean;
}

interface Props {
    post: PostData | null;
    categories: { id: number; parent_id: number | null; name: string }[];
    can: Record<string, boolean>;
    isAdmin: boolean;
    authorName: string;
}

const selectClass = 'border-input bg-card h-9 w-full rounded-md border px-2 text-sm';

function Counter({ value, max }: { value: string; max: number }) {
    return (
        <span className={`text-xs ${value.length > max ? 'text-red-600' : 'text-muted-foreground'}`}>
            {value.length}/{max}
        </span>
    );
}

function PostForm({ post, categories, can, isAdmin, authorName }: Props) {
    const editable = can.update !== false && !post?.trashed;
    const form = useForm({
        action: 'save',
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        meta_title: post?.meta_title ?? '',
        meta_description: post?.meta_description ?? '',
        category_id: post ? String(post.category_id) : '',
        tag_ids: (post?.tags ?? []).map((t) => t.id),
        featured_media_id: post?.featured?.id ?? (null as number | null),
        published_at: toLocalInput(post?.published_at ?? null),
        version: post?.version ?? 0,
    });
    const [tags, setTags] = useState<Option[]>(post?.tags ?? []);
    const [featured, setFeatured] = useState<MediaItem | null>(post?.featured ?? null);
    const [picker, setPicker] = useState(false);
    const [preview, setPreview] = useState(false);
    const [revisions, setRevisions] = useState(false);
    const [autosave, setAutosave] = useState<string>('');
    const [conflict, setConflict] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Posts', href: '/admin/posts' },
        { title: post ? 'Edit' : 'New', href: post ? `/admin/posts/${post.id}` : '/admin/posts/create' },
    ];

    const send = (action: string) => {
        form.transform((d) => ({
            ...d,
            action,
            category_id: d.category_id || null,
            published_at: fromLocalInput(d.published_at),
        }));
        const opts = { preserveScroll: true };
        if (post) form.put(route('admin.posts.update', post.id), opts);
        else form.post(route('admin.posts.store'), opts);
    };

    // Draft autosave every 30s while there are unsaved changes (FR-37).
    const autosavable = !!post && editable && ['draft', 'rejected'].includes(post.status);
    useEffect(() => {
        if (!autosavable) return;
        const timer = setInterval(async () => {
            if (!form.isDirty || !form.data.title) return;
            const { status, data } = await postJson<{ version: number }>(
                route('admin.posts.autosave', post!.id),
                { title: form.data.title, excerpt: form.data.excerpt, body: form.data.body, version: form.data.version },
                'PUT',
            );
            if (status === 409) return setConflict(true);
            if (status === 200) {
                form.setData('version', data.version);
                form.setDefaults();
                setAutosave(`Autosaved ${new Date().toLocaleTimeString()}`);
            }
        }, 30000);
        return () => clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autosavable, form.isDirty, form.data.title, form.data.excerpt, form.data.body, form.data.version]);

    const move = (name: string, data: Record<string, string> = {}) => router.post(route(name, post!.id), data, { preserveScroll: true });
    const reject = () => {
        const note = prompt('Why is this post being sent back? The writer will see this note.');
        if (note) move('admin.posts.reject', { review_note: note });
    };

    const flatCategories = categories.filter((c) => c.parent_id === null).flatMap((p) => [p, ...categories.filter((c) => c.parent_id === p.id)]);
    const errors = form.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={post ? `Edit: ${post.title}` : 'New post'} />

            <div className="space-y-4 p-4">
                {post?.status === 'rejected' && post.review_note && (
                    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <strong>Sent back for changes:</strong> {post.review_note}
                    </div>
                )}
                {(errors.version || conflict) && (
                    <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        This post was changed elsewhere.{' '}
                        <button className="underline" onClick={() => router.reload()}>
                            Reload
                        </button>{' '}
                        to see the latest version (your edits on this screen will be lost).
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <div className="min-w-64 flex-1">
                        <Input
                            aria-label="Title"
                            placeholder="Post title"
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
                                    {(!post || can.submit) && !isAdmin && (
                                        <DropdownMenuItem onClick={() => send('submit')}>Submit for review</DropdownMenuItem>
                                    )}
                                    {isAdmin && (!post || can.publish) && (
                                        <>
                                            <DropdownMenuItem onClick={() => send('publish')}>Publish now</DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => send('schedule')}>Schedule for the date set →</DropdownMenuItem>
                                        </>
                                    )}
                                    {isAdmin && post && can.submit && (
                                        <DropdownMenuItem onClick={() => send('submit')}>Submit for review</DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
                    <div className="space-y-2">
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
                                                canEditSource={isAdmin}
                                            />
                                            <InputError message={errors.body} />
                                        </>
                                    ),
                                },
                                {
                                    id: 'meta',
                                    label: 'Meta',
                                    content: (
                                        <>
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
                                            <div className="grid gap-2">
                                                <Label htmlFor="excerpt">Excerpt</Label>
                                                <textarea
                                                    id="excerpt"
                                                    rows={3}
                                                    maxLength={500}
                                                    disabled={!editable}
                                                    className="border-input bg-card rounded-md border p-2 text-sm"
                                                    value={form.data.excerpt}
                                                    onChange={(e) => form.setData('excerpt', e.target.value)}
                                                />
                                                <Counter value={form.data.excerpt} max={500} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label>Featured image</Label>
                                                {featured && (
                                                    <img
                                                        src={featured.thumb_url ?? featured.url}
                                                        alt={featured.alt_text ?? ''}
                                                        className="max-h-40 w-fit rounded-md border"
                                                    />
                                                )}
                                                {editable && (
                                                    <div className="flex gap-2">
                                                        <Button type="button" variant="outline" size="sm" onClick={() => setPicker(true)}>
                                                            <ImageIcon className="size-4" /> {featured ? 'Change' : 'Choose image'}
                                                        </Button>
                                                        {featured && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setFeatured(null);
                                                                    form.setData('featured_media_id', null);
                                                                }}
                                                            >
                                                                Remove
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                                <InputError message={errors.featured_media_id} />
                                            </div>
                                        </>
                                    ),
                                },
                                {
                                    id: 'seo',
                                    label: 'SEO',
                                    content: (
                                        <>
                                            <div className="grid gap-2">
                                                <div className="flex justify-between">
                                                    <Label htmlFor="meta_title">Meta title</Label>
                                                    <Counter value={form.data.meta_title} max={70} />
                                                </div>
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
                                                <div className="flex justify-between">
                                                    <Label htmlFor="meta_description">Meta description</Label>
                                                    <Counter value={form.data.meta_description} max={160} />
                                                </div>
                                                <textarea
                                                    id="meta_description"
                                                    rows={3}
                                                    disabled={!editable}
                                                    className="border-input bg-card rounded-md border p-2 text-sm"
                                                    value={form.data.meta_description}
                                                    onChange={(e) => form.setData('meta_description', e.target.value)}
                                                    placeholder={form.data.excerpt}
                                                />
                                                <InputError message={errors.meta_description} />
                                            </div>
                                        </>
                                    ),
                                },
                            ]}
                        />
                        {autosave && <p className="text-muted-foreground text-xs">{autosave}</p>}
                    </div>

                    <aside className="space-y-4">
                        <div className="bg-card space-y-4 rounded-xl border p-4 shadow-xs">
                            <div>
                                <Label className="text-muted-foreground text-xs">Author</Label>
                                <p className="text-sm font-medium">{post?.author ?? authorName}</p>
                            </div>
                            <div>
                                <Label className="text-muted-foreground text-xs">Status</Label>
                                <div className="pt-1">
                                    <StatusBadge status={post?.trashed ? 'trashed' : (post?.status ?? 'draft')} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="published_at" className="text-muted-foreground text-xs">
                                    Post date
                                </Label>
                                <Input
                                    id="published_at"
                                    type="datetime-local"
                                    disabled={!isAdmin || !editable}
                                    value={form.data.published_at}
                                    onChange={(e) => form.setData('published_at', e.target.value)}
                                />
                                <InputError message={errors.published_at} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="category" className="text-muted-foreground text-xs">
                                    Category
                                </Label>
                                <select
                                    id="category"
                                    className={selectClass}
                                    disabled={!editable}
                                    value={form.data.category_id}
                                    onChange={(e) => form.setData('category_id', e.target.value)}
                                >
                                    <option value="">Uncategorized</option>
                                    {flatCategories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.parent_id ? '— ' : ''}
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.category_id} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-muted-foreground text-xs">Tags</Label>
                                <TagInput
                                    value={tags}
                                    disabled={!editable}
                                    onChange={(next) => {
                                        setTags(next);
                                        form.setData(
                                            'tag_ids',
                                            next.map((t) => t.id),
                                        );
                                    }}
                                />
                            </div>
                        </div>

                        {post && (
                            <div className="bg-card flex flex-col gap-2 rounded-xl border p-4 shadow-xs">
                                <Button variant="outline" size="sm" onClick={() => setRevisions(true)}>
                                    <History className="size-4" /> Revisions
                                </Button>
                                {can.reject && (
                                    <Button variant="outline" size="sm" onClick={reject}>
                                        Send back with a note
                                    </Button>
                                )}
                                {can.unpublish && (
                                    <Button variant="outline" size="sm" onClick={() => move('admin.posts.unpublish')}>
                                        Unpublish
                                    </Button>
                                )}
                                {can.archive && (
                                    <Button variant="outline" size="sm" onClick={() => move('admin.posts.archive')}>
                                        Archive
                                    </Button>
                                )}
                                {can.unarchive && (
                                    <Button variant="outline" size="sm" onClick={() => move('admin.posts.unarchive')}>
                                        Restore from archive
                                    </Button>
                                )}
                                {can.untrash && (
                                    <Button variant="outline" size="sm" onClick={() => move('admin.posts.restore')}>
                                        Restore from trash
                                    </Button>
                                )}
                                {can.trash && (
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={() =>
                                            confirm('Move this post to the trash?') && router.delete(route('admin.posts.destroy', post.id))
                                        }
                                    >
                                        Move to trash
                                    </Button>
                                )}
                                {can.purge && (
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={() =>
                                            confirm('Delete permanently? This cannot be undone.') &&
                                            router.delete(route('admin.posts.purge', post.id))
                                        }
                                    >
                                        Delete permanently
                                    </Button>
                                )}
                            </div>
                        )}
                    </aside>
                </div>
            </div>

            <MediaPicker
                kind="image"
                open={picker}
                onClose={() => setPicker(false)}
                onPick={(m) => {
                    setFeatured(m);
                    form.setData('featured_media_id', m.id);
                    setPicker(false);
                }}
            />
            <PreviewDialog open={preview} onClose={() => setPreview(false)} title={form.data.title} body={form.data.body} />
            {post && (
                <RevisionsDialog
                    open={revisions}
                    onClose={() => setRevisions(false)}
                    listUrl={route('admin.posts.revisions', post.id)}
                    restoreUrl={(rev) => route('admin.posts.revisions.restore', [post.id, rev])}
                />
            )}
        </AppLayout>
    );
}

// Remount after each successful save so the form picks up the new version from the server.
export default function Form(props: Props) {
    return <PostForm key={`${props.post?.id ?? 'new'}-${props.post?.version ?? 0}`} {...props} />;
}
