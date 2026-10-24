import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Revision {
    id: number;
    title: string;
    editor: string;
    created_at: string;
    snippet: string;
}

interface Props {
    open: boolean;
    onClose: () => void;
    listUrl: string;
    restoreUrl: (revisionId: number) => string;
}

export function RevisionsDialog({ open, onClose, listUrl, restoreUrl }: Props) {
    const [items, setItems] = useState<Revision[] | null>(null);

    useEffect(() => {
        if (!open) return;
        fetch(listUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then(setItems);
    }, [open, listUrl]);

    const restore = (r: Revision) => {
        if (!confirm(`Restore the version from ${new Date(r.created_at).toLocaleString()}? Unsaved changes are lost.`)) return;
        router.post(restoreUrl(r.id), {}, { onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Revisions</DialogTitle>
                    <DialogDescription>The latest 50 saved versions. Restoring creates a new revision.</DialogDescription>
                </DialogHeader>
                <ul className="divide-y rounded-md border">
                    {items?.map((r) => (
                        <li key={r.id} className="flex items-start justify-between gap-3 p-3 text-sm">
                            <div className="min-w-0">
                                <p className="truncate font-medium">{r.title}</p>
                                <p className="text-muted-foreground text-xs">
                                    {new Date(r.created_at).toLocaleString()} · {r.editor}
                                </p>
                                <p className="text-muted-foreground mt-1 line-clamp-2 text-xs">{r.snippet}</p>
                            </div>
                            <Button size="sm" variant="outline" onClick={() => restore(r)}>
                                Restore
                            </Button>
                        </li>
                    ))}
                    {items?.length === 0 && <li className="text-muted-foreground p-3 text-sm">No revisions yet.</li>}
                    {items === null && <li className="text-muted-foreground p-3 text-sm">Loading…</li>}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
