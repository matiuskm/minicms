export const HEX = /^#(?:[0-9a-f]{6}|[0-9a-f]{8})$/i;

export function isHex(value: string): boolean {
    return HEX.test(value);
}

/** Accepts #rgb as a typing convenience and expands it; anything else is returned untouched. */
export function normalizeHex(value: string): string {
    const v = value.trim().toLowerCase();
    return /^#[0-9a-f]{3}$/.test(v) ? `#${v[1]}${v[1]}${v[2]}${v[2]}${v[3]}${v[3]}` : v;
}

function luminance(hex: string): number {
    const channel = (i: number) => {
        const c = parseInt(hex.slice(1 + i, 3 + i), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);
}

/** WCAG contrast ratio (1–21); alpha is ignored. */
export function contrast(a: string, b: string): number {
    const [l1, l2] = [luminance(a), luminance(b)];
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

/** White or near-black, whichever reads better on `bg` (mirrors ThemeCss on the server). */
export function onColor(bg: string): string {
    return contrast(bg, '#ffffff') >= contrast(bg, '#111111') ? '#ffffff' : '#111111';
}
