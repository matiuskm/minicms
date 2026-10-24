import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { fetchMedia, formatSize, uploadMedia, type MediaItem } from '@/lib/media';
import { FileText } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    kind: 'image' | 'pdf';
    open: boolean;
    onClose: () => void;
    onPick: (item: MediaItem) => void;
}

export function MediaPicker({ kind, open, onClose, onPick }: Props) {
    const [items, setItems] = useState<MediaItem[]>([]);
    const [error, setError] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [alt, setAlt] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        fetchMedia(kind)
            .then(setItems)
            .catch((e: Error) => setError(e.message));
    }, [open, kind]);

    const upload = async () => {
        if (!file) return;
        setBusy(true);
        setError('');
        try {
            const item = await uploadMedia(file, alt);
            setFile(null);
            setAlt('');
            onPick(item);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{kind === 'image' ? 'Insert image' : 'Insert PDF link'}</DialogTitle>
                    <DialogDescription>Choose from your library or upload a new file.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_auto]">
                    <Input
                        type="file"
                        aria-label="File"
                        accept={kind === 'image' ? 'image/jpeg,image/png,image/gif,image/webp' : 'application/pdf'}
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                    <Input
                        aria-label={kind === 'image' ? 'Alt text' : 'Title'}
                        placeholder={kind === 'image' ? 'Alt text (required)' : 'Title (required)'}
                        value={alt}
                        onChange={(e) => setAlt(e.target.value)}
                    />
                    <Button type="button" disabled={!file || !alt || busy} onClick={upload}>
                        Upload
                    </Button>
                </div>
                {error && <p className="text-sm text-red-600">{error}</p>}

                <ul className="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
                    {items.map((m) => (
                        <li key={m.id}>
                            <button
                                type="button"
                                onClick={() => onPick(m)}
                                className="hover:border-primary w-full rounded-md border p-1 text-left text-xs"
                            >
                                {m.thumb_url ? (
                                    <img src={m.thumb_url} alt={m.alt_text ?? ''} className="aspect-square w-full rounded object-cover" />
                                ) : (
                                    <div className="bg-muted flex aspect-square items-center justify-center rounded">
                                        <FileText className="size-8" />
                                    </div>
                                )}
                                <span className="mt-1 block truncate">{m.alt_text || m.name}</span>
                                <span className="text-muted-foreground">{formatSize(m.size)}</span>
                            </button>
                        </li>
                    ))}
                    {items.length === 0 && <li className="text-muted-foreground col-span-full text-sm">Nothing here yet.</li>}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
