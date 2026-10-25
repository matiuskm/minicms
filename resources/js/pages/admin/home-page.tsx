import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ImageIcon, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { MediaPicker } from '@/components/editor/media-picker';
import { RichEditor } from '@/components/editor/rich-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type MediaItem } from '@/lib/media';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Home page', href: '/admin/home-page' }];

type Type = 'featured' | 'image' | 'split' | 'slider' | 'rich_text' | 'post_grid' | 'cta' | 'blog';

interface Slide {
    media_id: number;
    thumb: string | null;
    title: string;
    text: string;
    button_label: string;
    button_url: string;
}
interface Section {
    id: string;
    type: Type;
    enabled: boolean;
    post_id?: number | null;
    media_id?: number;
    thumb?: string | null;
    title?: string;
    text?: string;
    align?: 'left' | 'center' | 'right';
    image_side?: 'left' | 'right';
    button_label?: string;
    button_url?: string;
    autoplay?: boolean;
    slides?: Slide[];
    body?: string;
    source?: 'latest' | 'category' | 'tag';
    category_id?: number | null;
    tag_id?: number | null;
    count?: number;
}

interface Props {
    sections: Section[];
    posts: { id: number; title: string }[];
    categories: { id: number; name: string }[];
    tags: { id: number; name: string }[];
}

const LABELS: Record<Type, string> = {
    featured: 'Featured post',
    image: 'Hero image',
    split: 'Text + image',
    slider: 'Hero slider',
    rich_text: 'Text',
    post_grid: 'Post grid',
    cta: 'Call to action',
    blog: 'Blog (post list)',
};
const ADDABLE: Type[] = ['featured', 'image', 'split', 'slider', 'rich_text', 'post_grid', 'cta'];
const MAX = 12;
const selectClass = 'border-input bg-card h-9 w-full rounded-md border px-2 text-sm';
const id = () => Math.random().toString(36).slice(2, 10);

const blank = (type: Type): Section => {
    const base = { id: id(), type, enabled: true };
    switch (type) {
        case 'featured':
            return { ...base, post_id: null };
        case 'image':
            return { ...base, title: '', text: '', align: 'left', button_label: '', button_url: '' };
        case 'split':
            return { ...base, title: '', body: '', image_side: 'right', button_label: '', button_url: '' };
        case 'slider':
            return { ...base, autoplay: true, slides: [] };
        case 'rich_text':
            return { ...base, title: '', body: '' };
        case 'post_grid':
            return { ...base, title: 'Latest', source: 'latest', category_id: null, tag_id: null, count: 3 };
        case 'cta':
            return { ...base, title: '', text: '', button_label: '', button_url: '' };
        default:
            return { ...base, title: '' };
    }
};

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export default function HomePage({ sections: initial, posts, categories, tags }: Props) {
    const [items, setItems] = useState<Section[]>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    // The saved (or reset) layout comes back as new props; show that, not the draft.
    useEffect(() => setItems(initial), [initial]);
    const [pick, setPick] = useState<((m: MediaItem) => void) | null>(null);

    const patch = (i: number, change: Partial<Section>) => setItems(items.map((s, n) => (n === i ? { ...s, ...change } : s)));
    const move = (i: number, d: -1 | 1) => {
        const next = [...items];
        [next[i], next[i + d]] = [next[i + d], next[i]];
        setItems(next);
    };
    const err = (path: string) => errors[path];

    const save = () => {
        setBusy(true);
        router.put(route('admin.home-page.update'), { sections: items } as never, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    const choose = (done: (m: MediaItem) => void) => setPick(() => done);

    const Image = ({ thumb, onChoose }: { thumb?: string | null; onChoose: () => void }) => (
        <div className="flex items-center gap-3">
            {thumb ? (
                <img src={thumb} alt="" className="size-14 rounded-md border object-cover" />
            ) : (
                <span className="bg-muted size-14 rounded-md border" />
            )}
            <Button type="button" size="sm" variant="outline" onClick={onChoose}>
                <ImageIcon className="size-4" /> {thumb ? 'Change image' : 'Choose image'}
            </Button>
        </div>
    );

    const button = (path: string, v: { button_label?: string; button_url?: string }, set: (c: Partial<Section>) => void) => (
        <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Button label" error={err(`${path}.button_label`)}>
                <Input maxLength={40} value={v.button_label ?? ''} onChange={(e) => set({ button_label: e.target.value })} />
            </Field>
            <Field label="Button link" error={err(`${path}.button_url`)}>
                <Input placeholder="https://… or /path" value={v.button_url ?? ''} onChange={(e) => set({ button_url: e.target.value })} />
            </Field>
        </div>
    );

    const editor = (s: Section, i: number) => {
        const p = `sections.${i}`;
        switch (s.type) {
            case 'featured':
                return (
                    <Field label="Feature" error={err(`${p}.post_id`)}>
                        <select
                            className={selectClass}
                            value={s.post_id ?? ''}
                            onChange={(e) => patch(i, { post_id: Number(e.target.value) || null })}
                        >
                            <option value="">Newest post (automatic)</option>
                            {posts.map((x) => (
                                <option key={x.id} value={x.id}>
                                    {x.title}
                                </option>
                            ))}
                        </select>
                    </Field>
                );
            case 'image':
                return (
                    <div className="grid gap-3">
                        <Field label="Image" error={err(`${p}.media_id`)}>
                            <Image thumb={s.thumb} onChoose={() => choose((m) => patch(i, { media_id: m.id, thumb: m.thumb_url }))} />
                        </Field>
                        <Field label="Title" error={err(`${p}.title`)}>
                            <Input maxLength={120} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                        </Field>
                        <Field label="Text" error={err(`${p}.text`)}>
                            <Input maxLength={400} value={s.text ?? ''} onChange={(e) => patch(i, { text: e.target.value })} />
                        </Field>
                        <Field label="Text position">
                            <select className={selectClass} value={s.align} onChange={(e) => patch(i, { align: e.target.value as Section['align'] })}>
                                <option value="left">Left</option>
                                <option value="center">Centre</option>
                                <option value="right">Right</option>
                            </select>
                        </Field>
                        {button(p, s, (c) => patch(i, c))}
                    </div>
                );
            case 'split':
                return (
                    <div className="grid gap-3">
                        <Field label="Image" error={err(`${p}.media_id`)}>
                            <Image thumb={s.thumb} onChoose={() => choose((m) => patch(i, { media_id: m.id, thumb: m.thumb_url }))} />
                        </Field>
                        <Field label="Image position">
                            <select
                                className={selectClass}
                                value={s.image_side}
                                onChange={(e) => patch(i, { image_side: e.target.value as Section['image_side'] })}
                            >
                                <option value="right">Right of the text</option>
                                <option value="left">Left of the text</option>
                            </select>
                        </Field>
                        <Field label="Heading" error={err(`${p}.title`)}>
                            <Input maxLength={160} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                        </Field>
                        <RichEditor value={s.body ?? ''} onChange={(html) => patch(i, { body: html })} />
                        <InputError message={err(`${p}.body`)} />
                        {button(p, s, (c) => patch(i, c))}
                    </div>
                );
            case 'slider': {
                const slides = s.slides ?? [];
                const setSlide = (n: number, c: Partial<Slide>) => patch(i, { slides: slides.map((x, k) => (k === n ? { ...x, ...c } : x)) });
                return (
                    <div className="grid gap-3">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={!!s.autoplay} onChange={(e) => patch(i, { autoplay: e.target.checked })} />
                            Advance automatically (stops on hover, and for visitors who prefer less motion)
                        </label>
                        <InputError message={err(`${p}.slides`)} />
                        {slides.map((sl, n) => (
                            <div key={n} className="grid gap-3 rounded-lg border p-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium">Slide {n + 1}</span>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove slide ${n + 1}`}
                                        onClick={() => patch(i, { slides: slides.filter((_, k) => k !== n) })}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                                <Field label="Image" error={err(`${p}.slides.${n}.media_id`)}>
                                    <Image thumb={sl.thumb} onChoose={() => choose((m) => setSlide(n, { media_id: m.id, thumb: m.thumb_url }))} />
                                </Field>
                                <Field label="Title" error={err(`${p}.slides.${n}.title`)}>
                                    <Input maxLength={120} value={sl.title} onChange={(e) => setSlide(n, { title: e.target.value })} />
                                </Field>
                                <Field label="Text" error={err(`${p}.slides.${n}.text`)}>
                                    <Input maxLength={300} value={sl.text} onChange={(e) => setSlide(n, { text: e.target.value })} />
                                </Field>
                                {button(`${p}.slides.${n}`, sl, (c) => setSlide(n, c as Partial<Slide>))}
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={slides.length >= 8}
                                onClick={() =>
                                    patch(i, {
                                        slides: [...slides, { media_id: 0, thumb: null, title: '', text: '', button_label: '', button_url: '' }],
                                    })
                                }
                            >
                                <Plus className="size-4" /> Add slide
                            </Button>
                        </div>
                    </div>
                );
            }
            case 'rich_text':
                return (
                    <div className="grid gap-3">
                        <Field label="Heading (optional)" error={err(`${p}.title`)}>
                            <Input maxLength={120} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                        </Field>
                        <RichEditor value={s.body ?? ''} onChange={(html) => patch(i, { body: html })} />
                        <InputError message={err(`${p}.body`)} />
                    </div>
                );
            case 'post_grid':
                return (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Heading" error={err(`${p}.title`)}>
                            <Input maxLength={120} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                        </Field>
                        <Field label="Number of posts" error={err(`${p}.count`)}>
                            <Input
                                type="number"
                                min={3}
                                max={12}
                                value={s.count ?? 3}
                                onChange={(e) => patch(i, { count: Number(e.target.value) })}
                            />
                        </Field>
                        <Field label="Show">
                            <select
                                className={selectClass}
                                value={s.source}
                                onChange={(e) => patch(i, { source: e.target.value as Section['source'] })}
                            >
                                <option value="latest">Latest posts</option>
                                <option value="category">From a category</option>
                                <option value="tag">With a tag</option>
                            </select>
                        </Field>
                        {s.source === 'category' && (
                            <Field label="Category" error={err(`${p}.category_id`)}>
                                <select
                                    className={selectClass}
                                    value={s.category_id ?? ''}
                                    onChange={(e) => patch(i, { category_id: Number(e.target.value) || null })}
                                >
                                    <option value="">Choose…</option>
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                        {s.source === 'tag' && (
                            <Field label="Tag" error={err(`${p}.tag_id`)}>
                                <select
                                    className={selectClass}
                                    value={s.tag_id ?? ''}
                                    onChange={(e) => patch(i, { tag_id: Number(e.target.value) || null })}
                                >
                                    <option value="">Choose…</option>
                                    {tags.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                    </div>
                );
            case 'cta':
                return (
                    <div className="grid gap-3">
                        <Field label="Title" error={err(`${p}.title`)}>
                            <Input maxLength={120} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                        </Field>
                        <Field label="Text" error={err(`${p}.text`)}>
                            <Input maxLength={300} value={s.text ?? ''} onChange={(e) => patch(i, { text: e.target.value })} />
                        </Field>
                        {button(p, s, (c) => patch(i, c))}
                    </div>
                );
            default:
                return (
                    <Field label="Heading (optional)" error={err(`${p}.title`)}>
                        <Input maxLength={120} value={s.title ?? ''} onChange={(e) => patch(i, { title: e.target.value })} />
                    </Field>
                );
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Home page" />
            <div className="max-w-4xl space-y-4 p-4">
                <p className="text-muted-foreground text-sm">
                    The home page is built from these sections, top to bottom. The Blog section carries the post list and page numbers, so it can be
                    moved or hidden but not removed.
                </p>
                <InputError message={err('sections')} />
                {Object.keys(errors).length > 0 && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        Nothing was saved. {Object.keys(errors).length === 1 ? 'One problem' : `${Object.keys(errors).length} problems`} to fix:{' '}
                        {Object.values(errors).slice(0, 3).join(' ')}
                    </p>
                )}

                {items.map((s, i) => (
                    <section key={s.id} className={`bg-card space-y-4 rounded-xl border p-5 shadow-xs ${s.enabled ? '' : 'opacity-60'}`}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">{LABELS[s.type]}</h2>
                            <div className="flex items-center gap-1">
                                <label className="mr-2 flex items-center gap-1.5 text-sm">
                                    <input type="checkbox" checked={s.enabled} onChange={(e) => patch(i, { enabled: e.target.checked })} /> Show
                                </label>
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
                                {s.type !== 'blog' && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Remove section"
                                        onClick={() => setItems(items.filter((_, n) => n !== i))}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                        </div>
                        {editor(s, i)}
                    </section>
                ))}

                <div className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label="Add a section"
                        className={`${selectClass} max-w-56`}
                        value=""
                        disabled={items.length >= MAX}
                        onChange={(e) => e.target.value && setItems([...items, blank(e.target.value as Type)])}
                    >
                        <option value="">Add a section…</option>
                        {ADDABLE.map((t) => (
                            <option key={t} value={t}>
                                {LABELS[t]}
                            </option>
                        ))}
                    </select>
                    <Button type="button" disabled={busy} onClick={save}>
                        Save home page
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            confirm('Go back to the default home page layout?') &&
                            router.post(route('admin.home-page.reset'), {}, { preserveScroll: true })
                        }
                    >
                        Reset to default
                    </Button>
                </div>
            </div>

            <MediaPicker
                kind="image"
                open={pick !== null}
                onClose={() => setPick(null)}
                onPick={(m) => {
                    pick?.(m);
                    setPick(null);
                }}
            />
        </AppLayout>
    );
}
