import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatSize, type MediaItem, type Paginated } from '@/lib/media';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Media', href: '/media' }];

type Item = MediaItem & { uploader: string | null };

interface Props {
    items: Paginated<Item>;
    filters: { q: string; kind: string | null };
    limits: { image_mb: number; pdf_mb: number };
}

function Card({ item }: { item: Item }) {
    const [alt, setAlt] = useState(item.alt_text ?? '');

    return (
        <li className="bg-card flex flex-col gap-2 rounded-xl border p-2 shadow-xs">
            {item.thumb_url ? (
                <img src={item.thumb_url} alt={item.alt_text ?? ''} className="aspect-square w-full rounded-lg object-cover" />
            ) : (
                <a
                    href={item.url}
                    className="bg-muted flex aspect-square items-center justify-center rounded-lg"
                    aria-label={`Download ${item.name}`}
                >
                    <FileText className="text-muted-foreground size-10" />
                </a>
            )}
            <div className="min-w-0 text-xs">
                <p className="truncate font-medium">{item.name}</p>
                <p className="text-muted-foreground">
                    {formatSize(item.size)}
                    {item.uploader && ` · ${item.uploader}`}
                </p>
            </div>
            <Input
                aria-label={item.kind === 'image' ? 'Alt text' : 'Title'}
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                className="h-8 text-xs"
            />
            <div className="flex gap-1">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!alt || alt === item.alt_text}
                    onClick={() => router.put(route('media.update', item.id), { alt_text: alt }, { preserveScroll: true })}
                >
                    Save
                </Button>
                <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => confirm(`Delete ${item.name}?`) && router.delete(route('media.destroy', item.id), { preserveScroll: true })}
                >
                    Delete
                </Button>
            </div>
        </li>
    );
}

export default function MediaIndex({ items, filters, limits }: Props) {
    const { flash } = usePage<SharedData & { flash?: { status?: string } }>().props;
    const upload = useForm<{ file: File | null; alt_text: string }>({ file: null, alt_text: '' });
    const [q, setQ] = useState(filters.q);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('media.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
    };

    const filter = (next: Record<string, string | null>) =>
        router.get(route('media.index'), { q, kind: filters.kind, ...next }, { preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Media" />

            <div className="space-y-6 p-4">
                {flash?.status && <p className="text-sm text-green-600">{flash.status}</p>}

                <form
                    onSubmit={submit}
                    className="bg-card grid items-start gap-3 rounded-xl border border-dashed p-4 shadow-xs md:grid-cols-[1fr_1fr_auto]"
                >
                    <div className="grid gap-1">
                        <Input
                            type="file"
                            aria-label="File"
                            accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={upload.errors.file} />
                        <p className="text-muted-foreground text-xs">
                            JPG, PNG, GIF, WebP up to {limits.image_mb} MB · PDF up to {limits.pdf_mb} MB
                        </p>
                    </div>
                    <div className="grid gap-1">
                        <Input
                            aria-label="Alt text or title"
                            placeholder="Alt text (images) or title (PDFs)"
                            value={upload.data.alt_text}
                            onChange={(e) => upload.setData('alt_text', e.target.value)}
                        />
                        <InputError message={upload.errors.alt_text} />
                    </div>
                    <Button disabled={!upload.data.file || upload.processing}>Upload</Button>
                </form>

                <div className="flex flex-wrap gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter({});
                        }}
                        className="flex gap-2"
                    >
                        <Input placeholder="Search files" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" />
                        <Button variant="outline">Search</Button>
                    </form>
                    {[
                        ['All', null],
                        ['Images', 'image'],
                        ['PDFs', 'pdf'],
                    ].map(([label, kind]) => (
                        <Button key={label} variant={filters.kind === kind ? 'secondary' : 'ghost'} onClick={() => filter({ kind })}>
                            {label}
                        </Button>
                    ))}
                </div>

                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                    {items.data.map((item) => (
                        <Card key={`${item.id}-${item.alt_text}`} item={item} />
                    ))}
                </ul>
                {items.data.length === 0 && <p className="text-muted-foreground text-sm">No files yet. Upload your first image or PDF above.</p>}

                <nav className="flex flex-wrap gap-1">
                    {items.links.map((l, i) => (
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
