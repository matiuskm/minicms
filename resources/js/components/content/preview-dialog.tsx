import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { postJson } from '@/lib/content';
import { useEffect, useState } from 'react';

/** Shows exactly what the server would store: the body goes through the real sanitizer first. */
export function PreviewDialog({ open, onClose, title, body }: { open: boolean; onClose: () => void; title: string; body: string }) {
    const [html, setHtml] = useState('');

    useEffect(() => {
        if (!open) return;
        postJson<{ html: string }>('/content/preview', { html: body }).then((r) => setHtml(r.data.html ?? ''));
    }, [open, body]);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{title || 'Untitled'}</DialogTitle>
                    <DialogDescription>Preview of the sanitised content.</DialogDescription>
                </DialogHeader>
                <div className="prose-editor" dangerouslySetInnerHTML={{ __html: html }} />
            </DialogContent>
        </Dialog>
    );
}
