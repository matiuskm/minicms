import '../../../css/fonts/all.css';

import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Copy, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import InputError from '@/components/input-error';
import { SitePreview } from '@/components/public/preview';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { isHex, normalizeHex } from '@/lib/color';
import { contrastIssues, TOKEN_LABELS, TOKENS, type Tokens } from '@/lib/theme';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Appearance', href: '/admin/appearance' }];

interface Palette {
    id: number;
    name: string;
    is_preset: boolean;
    is_active: boolean;
    tokens: Tokens;
}

interface Props {
    palettes: Palette[];
    fonts: { key: string; label: string; stack: string }[];
    typography: { heading: string; body: string; size: 's' | 'm' | 'l' };
    maxCustom: number;
}

const SIZES: [string, string, number][] = [
    ['s', 'Small', 0.9375],
    ['m', 'Medium', 1],
    ['l', 'Large', 1.125],
];
const selectClass = 'border-input bg-card h-9 w-full rounded-md border px-2 text-sm';
const BLANK: Tokens = {
    background: '#f1e5da',
    surface: '#ffffff',
    text: '#1d1b19',
    muted: '#7a726b',
    primary: '#8f3f1f',
    accent: '#f7ede5',
    border: '#ece4dc',
    link: '#8f3f1f',
};

function Swatches({ tokens }: { tokens: Tokens }) {
    return (
        <div className="flex overflow-hidden rounded-md border" aria-hidden>
            {(['background', 'surface', 'primary', 'accent', 'text'] as const).map((t) => (
                <span key={t} className="h-6 flex-1" style={{ background: tokens[t] }} />
            ))}
        </div>
    );
}

export default function Appearance({ palettes, fonts, typography, maxCustom }: Props) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const active = palettes.find((p) => p.is_active) ?? palettes[0];
    const customCount = palettes.filter((p) => !p.is_preset).length;

    // `editing`: null = not editing, 0 = new palette, otherwise the id being edited.
    const [editing, setEditing] = useState<number | null>(null);
    const [previewId, setPreviewId] = useState<number>(active.id);
    const editor = useForm<{ name: string; tokens: Tokens }>({ name: '', tokens: BLANK });
    const type = useForm({ ...typography });

    const stack = (key: string) => fonts.find((f) => f.key === key)?.stack ?? fonts[0].stack;
    const previewing = editing !== null ? editor.data.tokens : (palettes.find((p) => p.id === previewId) ?? active).tokens;
    const issues = useMemo(() => contrastIssues(previewing), [previewing]);
    const sizeScale = SIZES.find(([k]) => k === type.data.size)?.[2] ?? 1;

    const startNew = (from?: Palette) => {
        editor.setData({ name: '', tokens: from ? { ...from.tokens } : { ...active.tokens } });
        editor.clearErrors();
        setEditing(0);
    };
    const startEdit = (p: Palette) => {
        editor.setData({ name: p.name, tokens: { ...p.tokens } });
        editor.clearErrors();
        setEditing(p.id);
    };
    const save = () => {
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 0) editor.post(route('admin.appearance.palettes.store'), opts);
        else editor.put(route('admin.appearance.palettes.update', editing!), opts);
    };
    const setToken = (t: keyof Tokens, v: string) => editor.setData('tokens', { ...editor.data.tokens, [t]: v });
    const tokenErr = (t: string) => (editor.errors as Record<string, string>)[`tokens.${t}`];
    const act = (name: string, p: Palette) => router.post(route(name, p.id), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance" />

            <div className="grid gap-6 p-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,28rem)]">
                <div className="space-y-6">
                    {errors?.palette && <p className="text-sm text-red-600">{errors.palette}</p>}

                    <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="font-semibold">Colour palette</h2>
                                <p className="text-muted-foreground text-xs">
                                    One palette is live for every visitor. Built-in palettes can be duplicated, not edited.
                                </p>
                            </div>
                            <Button size="sm" variant="outline" disabled={customCount >= maxCustom || editing !== null} onClick={() => startNew()}>
                                New custom palette
                            </Button>
                        </div>

                        <ul className="grid gap-3 sm:grid-cols-2">
                            {palettes.map((p) => (
                                <li
                                    key={p.id}
                                    className={`space-y-2 rounded-lg border p-3 ${p.id === previewId && editing === null ? 'ring-primary ring-2' : ''}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium"
                                            onClick={() => {
                                                setPreviewId(p.id);
                                                setEditing(null);
                                            }}
                                        >
                                            {p.name}
                                        </button>
                                        <div className="flex gap-1">
                                            {p.is_active && <Badge>Live</Badge>}
                                            {p.is_preset && <Badge variant="secondary">Built-in</Badge>}
                                        </div>
                                    </div>
                                    <Swatches tokens={p.tokens} />
                                    <div className="flex flex-wrap gap-1">
                                        {!p.is_active && (
                                            <Button size="sm" onClick={() => act('admin.appearance.palettes.activate', p)}>
                                                <Check className="size-3.5" /> Use
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            aria-label={`Duplicate ${p.name}`}
                                            disabled={customCount >= maxCustom}
                                            onClick={() => act('admin.appearance.palettes.duplicate', p)}
                                        >
                                            <Copy className="size-3.5" /> Duplicate
                                        </Button>
                                        {!p.is_preset && (
                                            <>
                                                <Button size="sm" variant="outline" aria-label={`Edit ${p.name}`} onClick={() => startEdit(p)}>
                                                    <Pencil className="size-3.5" /> Edit
                                                </Button>
                                                {!p.is_active && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        aria-label={`Delete ${p.name}`}
                                                        onClick={() =>
                                                            confirm(`Delete “${p.name}”?`) &&
                                                            router.delete(route('admin.appearance.palettes.destroy', p.id), { preserveScroll: true })
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </Button>
                                                )}
                                            </>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {editing !== null && (
                        <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs" aria-label="Palette editor">
                            <h2 className="font-semibold">{editing === 0 ? 'New custom palette' : 'Edit palette'}</h2>
                            <div className="grid gap-1.5">
                                <Label htmlFor="palette-name">Name</Label>
                                <Input
                                    id="palette-name"
                                    maxLength={60}
                                    value={editor.data.name}
                                    onChange={(e) => editor.setData('name', e.target.value)}
                                />
                                <InputError message={editor.errors.name} />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {TOKENS.map((t) => (
                                    <div key={t} className="grid gap-1.5">
                                        <Label htmlFor={`tok-${t}`}>{TOKEN_LABELS[t]}</Label>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                aria-label={`${TOKEN_LABELS[t]} picker`}
                                                className="h-9 w-10 cursor-pointer rounded border p-0.5"
                                                value={isHex(editor.data.tokens[t]) ? editor.data.tokens[t].slice(0, 7) : '#000000'}
                                                onChange={(e) => setToken(t, e.target.value)}
                                            />
                                            <Input
                                                id={`tok-${t}`}
                                                className="font-mono"
                                                maxLength={9}
                                                value={editor.data.tokens[t]}
                                                onChange={(e) => setToken(t, e.target.value)}
                                                onBlur={(e) => setToken(t, normalizeHex(e.target.value))}
                                            />
                                        </div>
                                        <InputError message={tokenErr(t)} />
                                    </div>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <Button disabled={editor.processing || !editor.data.name} onClick={save}>
                                    Save palette
                                </Button>
                                <Button variant="ghost" onClick={() => setEditing(null)}>
                                    Cancel
                                </Button>
                            </div>
                        </section>
                    )}

                    <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
                        <h2 className="font-semibold">Typography</h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['heading', 'body'] as const).map((k) => (
                                <div key={k} className="grid gap-1.5">
                                    <Label htmlFor={`font-${k}`}>{k === 'heading' ? 'Heading font' : 'Body font'}</Label>
                                    <select
                                        id={`font-${k}`}
                                        className={selectClass}
                                        value={type.data[k]}
                                        onChange={(e) => type.setData(k, e.target.value)}
                                    >
                                        {fonts.map((f) => (
                                            <option key={f.key} value={f.key}>
                                                {f.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={type.errors[k]} />
                                </div>
                            ))}
                        </div>
                        <fieldset className="grid gap-1.5">
                            <legend className="text-sm font-medium">Base text size</legend>
                            <div className="flex gap-4 text-sm">
                                {SIZES.map(([k, label]) => (
                                    <label key={k} className="flex items-center gap-1.5">
                                        <input
                                            type="radio"
                                            name="size"
                                            checked={type.data.size === k}
                                            onChange={() => type.setData('size', k as 's' | 'm' | 'l')}
                                        />{' '}
                                        {label}
                                    </label>
                                ))}
                            </div>
                            <InputError message={type.errors.size} />
                        </fieldset>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={type.processing}
                                onClick={() => type.put(route('admin.appearance.typography'), { preserveScroll: true })}
                            >
                                Save typography
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    confirm('Reset the palette and fonts to the defaults?') &&
                                    router.post(route('admin.appearance.reset'), {}, { preserveScroll: true })
                                }
                            >
                                Reset to defaults
                            </Button>
                        </div>
                    </section>
                </div>

                <aside className="space-y-3 xl:sticky xl:top-4 xl:self-start">
                    <h2 className="font-semibold">Preview</h2>
                    <p className="text-muted-foreground text-xs">
                        {editing !== null ? 'Showing the palette you are editing.' : 'Showing the selected palette'} with the fonts and size chosen
                        here. Nothing goes live until you save or press “Use”.
                    </p>
                    <SitePreview tokens={previewing} headingStack={stack(type.data.heading)} bodyStack={stack(type.data.body)} scale={sizeScale} />
                    {issues.length > 0 ? (
                        <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <p className="flex items-center gap-1.5 font-medium">
                                <AlertTriangle className="size-4" /> Low contrast (WCAG AA needs 4.5:1)
                            </p>
                            <ul className="mt-1 list-disc pl-5 text-xs">
                                {issues.map((i) => (
                                    <li key={i.label}>
                                        {i.label}: {i.ratio}:1
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1 text-xs">You can still save, but some visitors may struggle to read the site.</p>
                        </div>
                    ) : (
                        <p className="text-xs text-emerald-700">Contrast looks good (AA).</p>
                    )}
                </aside>
            </div>
        </AppLayout>
    );
}
