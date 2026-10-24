export interface Option {
    id: number;
    name: string;
}

/** ISO 8601 (UTC) from the server -> value for <input type="datetime-local"> in the browser's timezone. */
export function toLocalInput(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Inverse of toLocalInput; empty stays null. */
export function fromLocalInput(local: string): string | null {
    return local ? new Date(local).toISOString() : null;
}

export const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    pending_review: 'Pending review',
    rejected: 'Rejected',
    scheduled: 'Scheduled',
    published: 'Published',
    archived: 'Archived',
    trashed: 'Trash',
};

export function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function postJson<T>(url: string, body: unknown, method = 'POST'): Promise<{ status: number; data: T }> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
    return { status: res.status, data: (await res.json().catch(() => ({}))) as T };
}
