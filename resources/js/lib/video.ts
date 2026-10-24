export type VideoRef = { provider: 'youtube' | 'vimeo'; id: string };

/** Turns a pasted YouTube/Vimeo URL into a provider + id. Anything else is rejected. */
export function parseVideoUrl(input: string): VideoRef | null {
    let url: URL;
    try {
        url = new URL(input.trim());
    } catch {
        return null;
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;

    const host = url.hostname.replace(/^www\./, '');
    let id: string | null = null;

    if (host === 'youtu.be') id = url.pathname.slice(1);
    else if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
        id = url.searchParams.get('v') ?? url.pathname.match(/^\/(?:embed|shorts)\/([^/?]+)/)?.[1] ?? null;
    } else if (host === 'vimeo.com' || host === 'player.vimeo.com') {
        const vimeo = url.pathname.match(/(?:^\/|\/video\/)(\d+)/)?.[1];
        return vimeo ? { provider: 'vimeo', id: vimeo } : null;
    } else return null;

    return id && /^[A-Za-z0-9_-]{6,32}$/.test(id) ? { provider: 'youtube', id } : null;
}

/** Same fixed hosts the server uses at render time; the author's URL is never embedded. */
export function embedSrc({ provider, id }: VideoRef): string {
    return provider === 'youtube' ? `https://www.youtube-nocookie.com/embed/${id}` : `https://player.vimeo.com/video/${id}`;
}
