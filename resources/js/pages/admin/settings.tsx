import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { MediaPicker } from '@/components/editor/media-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type MediaItem } from '@/lib/media';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Site settings', href: '/admin/settings' }];

type Settings = {
    site_name: string;
    tagline: string;
    logo_media_id: number | null;
    favicon_media_id: number | null;
    content_language: string;
    posts_per_page: number;
    cta_label: string;
    cta_url: string;
    pdf_max_mb: number;
    mail_from_name: string;
    mail_from_address: string;
    maintenance_mode: boolean;
};

interface Props {
    settings: Settings;
    logo: MediaItem | null;
    favicon: MediaItem | null;
    defaultName: string;
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export default function SettingsPage({ settings, logo: logo0, favicon: favicon0, defaultName }: Props) {
    const form = useForm<Settings>({ ...settings });
    const [logo, setLogo] = useState<MediaItem | null>(logo0);
    const [favicon, setFavicon] = useState<MediaItem | null>(favicon0);
    const [picking, setPicking] = useState<'logo' | 'favicon' | null>(null);
    const e = form.errors as Record<string, string>;

    const submit: FormEventHandler = (ev) => {
        ev.preventDefault();
        form.put(route('admin.settings.update'), { preserveScroll: true });
    };

    const image = (
        item: MediaItem | null,
        set: (m: MediaItem | null) => void,
        key: 'logo_media_id' | 'favicon_media_id',
        which: 'logo' | 'favicon',
    ) => (
        <div className="flex items-center gap-3">
            {item && <img src={item.thumb_url ?? item.url} alt={item.alt_text ?? ''} className="size-12 rounded-md border object-contain" />}
            <Button type="button" variant="outline" size="sm" onClick={() => setPicking(which)}>
                {item ? 'Change' : 'Choose image'}
            </Button>
            {item && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        set(null);
                        form.setData(key, null);
                    }}
                >
                    Remove
                </Button>
            )}
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Site settings" />

            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
                    <h2 className="font-semibold">Identity</h2>
                    <Field id="site_name" label="Site name" hint={`Leave empty to use “${defaultName}”.`} error={e.site_name}>
                        <Input
                            id="site_name"
                            value={form.data.site_name}
                            maxLength={60}
                            onChange={(ev) => form.setData('site_name', ev.target.value)}
                        />
                    </Field>
                    <Field id="tagline" label="Tagline" error={e.tagline}>
                        <Input id="tagline" value={form.data.tagline} maxLength={160} onChange={(ev) => form.setData('tagline', ev.target.value)} />
                    </Field>
                    <Field id="logo" label="Logo" hint="Replaces the site name in the header." error={e.logo_media_id}>
                        {image(logo, setLogo, 'logo_media_id', 'logo')}
                    </Field>
                    <Field id="favicon" label="Favicon" error={e.favicon_media_id}>
                        {image(favicon, setFavicon, 'favicon_media_id', 'favicon')}
                    </Field>
                    <Field
                        id="content_language"
                        label="Content language"
                        hint="Code used for the page language, e.g. en or id. Content itself can be in any language."
                        error={e.content_language}
                    >
                        <Input
                            id="content_language"
                            value={form.data.content_language}
                            maxLength={20}
                            className="max-w-40"
                            onChange={(ev) => form.setData('content_language', ev.target.value)}
                        />
                    </Field>
                </section>

                <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
                    <h2 className="font-semibold">Public site</h2>
                    <Field id="posts_per_page" label="Posts per page" hint="Between 5 and 50." error={e.posts_per_page}>
                        <Input
                            id="posts_per_page"
                            type="number"
                            min={5}
                            max={50}
                            className="max-w-32"
                            value={form.data.posts_per_page}
                            onChange={(ev) => form.setData('posts_per_page', Number(ev.target.value))}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="cta_label" label="Header button label" hint="Shown next to Login. Leave both empty for none." error={e.cta_label}>
                            <Input
                                id="cta_label"
                                value={form.data.cta_label}
                                maxLength={30}
                                onChange={(ev) => form.setData('cta_label', ev.target.value)}
                            />
                        </Field>
                        <Field id="cta_url" label="Header button link" error={e.cta_url}>
                            <Input
                                id="cta_url"
                                value={form.data.cta_url}
                                placeholder="https://… or /contact"
                                onChange={(ev) => form.setData('cta_url', ev.target.value)}
                            />
                        </Field>
                    </div>
                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.maintenance_mode}
                            onChange={(ev) => form.setData('maintenance_mode', ev.target.checked)}
                        />
                        <span>
                            <strong>Maintenance mode</strong>
                            <span className="text-muted-foreground block text-xs">
                                Visitors and writers see a “back soon” page. Signed-in admins keep working.
                            </span>
                        </span>
                    </label>
                </section>

                <section className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
                    <h2 className="font-semibold">Uploads and email</h2>
                    <Field
                        id="pdf_max_mb"
                        label="Maximum PDF size (MB)"
                        hint="1–20. Images are limited to 5 MB. PHP’s own upload limit must be at least as high."
                        error={e.pdf_max_mb}
                    >
                        <Input
                            id="pdf_max_mb"
                            type="number"
                            min={1}
                            max={20}
                            className="max-w-32"
                            value={form.data.pdf_max_mb}
                            onChange={(ev) => form.setData('pdf_max_mb', Number(ev.target.value))}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="mail_from_name" label="Email sender name" error={e.mail_from_name}>
                            <Input
                                id="mail_from_name"
                                value={form.data.mail_from_name}
                                maxLength={60}
                                onChange={(ev) => form.setData('mail_from_name', ev.target.value)}
                            />
                        </Field>
                        <Field
                            id="mail_from_address"
                            label="Email sender address"
                            hint="Used for invitations and password resets."
                            error={e.mail_from_address}
                        >
                            <Input
                                id="mail_from_address"
                                type="email"
                                value={form.data.mail_from_address}
                                onChange={(ev) => form.setData('mail_from_address', ev.target.value)}
                            />
                        </Field>
                    </div>
                </section>

                <Button disabled={form.processing}>Save settings</Button>
            </form>

            <MediaPicker
                kind="image"
                open={picking !== null}
                onClose={() => setPicking(null)}
                onPick={(m) => {
                    if (picking === 'logo') {
                        setLogo(m);
                        form.setData('logo_media_id', m.id);
                    } else {
                        setFavicon(m);
                        form.setData('favicon_media_id', m.id);
                    }
                    setPicking(null);
                }}
            />
        </AppLayout>
    );
}
