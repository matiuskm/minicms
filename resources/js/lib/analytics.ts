export const CHANNELS: { value: string; label: string }[] = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'x', label: 'X (Twitter)' },
    { value: 'telegram', label: 'Telegram' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'threads', label: 'Threads' },
    { value: 'line', label: 'LINE' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'youtube', label: 'YouTube' },
    { value: 'email', label: 'Email' },
];

const slug = (v: string) =>
    v
        .toLowerCase()
        .replace(/[^a-z0-9._ -]+/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .slice(0, 60);

/** A shareable link tagged with where it will be posted (and optionally a campaign name). */
export function buildLink(site: string, path: string, source: string, campaign = ''): string {
    const url = new URL(path, site.endsWith('/') ? site : `${site}/`);
    url.searchParams.set('utm_source', slug(source).slice(0, 20) || 'link');
    url.searchParams.set('utm_medium', 'social');
    const c = slug(campaign);
    if (c) url.searchParams.set('utm_campaign', c);
    return url.toString();
}

export const SOURCE_LABELS: Record<string, string> = {
    direct: 'Direct / unknown',
    internal: 'Inside this site',
    referral: 'Other websites',
    x: 'X (Twitter)',
    google: 'Google',
    instagram: 'Instagram',
    whatsapp: 'WhatsApp',
    facebook: 'Facebook',
};

export const sourceLabel = (s: string) => SOURCE_LABELS[s] ?? s.charAt(0).toUpperCase() + s.slice(1);
