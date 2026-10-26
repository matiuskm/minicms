import { useState } from 'react';

/** Every share link carries utm_source so Analytics reports where the visit came from (see Support/Analytics/Source). */
export function tagged(url: string, source: string): string {
    const u = new URL(url);
    u.searchParams.set('utm_source', source);
    u.searchParams.set('utm_medium', 'social');
    u.searchParams.set('utm_campaign', 'share');

    return u.toString();
}

const q = encodeURIComponent;

const targets = [
    { source: 'whatsapp', label: 'WhatsApp', href: (u: string, t: string) => `https://wa.me/?text=${q(`${t} ${u}`)}` },
    { source: 'facebook', label: 'Facebook', href: (u: string) => `https://www.facebook.com/sharer/sharer.php?u=${q(u)}` },
    { source: 'x', label: 'X', href: (u: string, t: string) => `https://twitter.com/intent/tweet?url=${q(u)}&text=${q(t)}` },
    { source: 'telegram', label: 'Telegram', href: (u: string, t: string) => `https://t.me/share/url?url=${q(u)}&text=${q(t)}` },
];

/** Buttons rather than links: ad blockers hide share links by their href. */
export function ShareButtons({ url, title }: { url: string; title: string }) {
    const [copied, setCopied] = useState(false);

    // Instagram has no web share link: copy the tagged URL to paste into a story, bio or DM.
    const copyForInstagram = async () => {
        try {
            await navigator.clipboard.writeText(tagged(url, 'instagram'));
            setCopied(true);
            setTimeout(() => setCopied(false), 3000);
        } catch {
            window.prompt('Copy this link', tagged(url, 'instagram'));
        }
    };

    return (
        <div className="site-share" aria-label="Share">
            <span>Share</span>
            {targets.map((t) => (
                <button
                    key={t.source}
                    type="button"
                    onClick={() => window.open(t.href(tagged(url, t.source), title), '_blank', 'noopener,noreferrer')}
                >
                    {t.label}
                </button>
            ))}
            <button type="button" onClick={copyForInstagram}>
                {copied ? 'Link copied, paste it in Instagram' : 'Instagram'}
            </button>
        </div>
    );
}
