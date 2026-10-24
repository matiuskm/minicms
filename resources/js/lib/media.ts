export interface MediaItem {
    id: number;
    uuid: string;
    kind: 'image' | 'pdf';
    name: string;
    alt_text: string | null;
    size: number;
    url: string;
    thumb_url: string | null;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

function csrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function fetchMedia(kind: 'image' | 'pdf', q = ''): Promise<MediaItem[]> {
    const res = await fetch(`/media?kind=${kind}&q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!res.ok) throw new Error('Could not load media.');
    return (await res.json()).data;
}

export async function uploadMedia(file: File, altText: string): Promise<MediaItem> {
    const body = new FormData();
    body.append('file', file);
    body.append('alt_text', altText);

    const res = await fetch('/media', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
    });

    if (!res.ok) {
        const json = await res.json().catch(() => null);
        throw new Error(json?.errors?.file?.[0] ?? json?.errors?.alt_text?.[0] ?? json?.message ?? 'Upload failed.');
    }
    return res.json();
}

export const formatSize = (bytes: number) =>
    bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
