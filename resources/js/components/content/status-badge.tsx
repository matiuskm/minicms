import { Badge } from '@/components/ui/badge';
import { STATUS_LABELS } from '@/lib/content';

const tone: Record<string, string> = {
    published: 'bg-emerald-100 text-emerald-800',
    scheduled: 'bg-sky-100 text-sky-800',
    pending_review: 'bg-amber-100 text-amber-800',
    rejected: 'bg-red-100 text-red-800',
    archived: 'bg-slate-200 text-slate-700',
    draft: 'bg-slate-100 text-slate-700',
    trashed: 'bg-slate-200 text-slate-700',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant="secondary" className={tone[status] ?? ''}>
            {STATUS_LABELS[status] ?? status}
        </Badge>
    );
}
