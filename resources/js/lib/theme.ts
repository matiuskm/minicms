import { contrast, isHex, onColor } from '@/lib/color';

export const TOKENS = ['background', 'surface', 'text', 'muted', 'primary', 'accent', 'border', 'link'] as const;
export type Token = (typeof TOKENS)[number];
export type Tokens = Record<Token, string>;

export const TOKEN_LABELS: Record<Token, string> = {
    background: 'Page background',
    surface: 'Content surface',
    text: 'Text',
    muted: 'Muted text',
    primary: 'Primary (buttons, accents)',
    accent: 'Soft accent (chips)',
    border: 'Borders',
    link: 'Links',
};

const VARS: Record<Token, string> = {
    background: '--site-bg',
    surface: '--site-surface',
    text: '--site-text',
    muted: '--site-muted',
    primary: '--site-primary',
    accent: '--site-soft',
    border: '--site-border',
    link: '--site-link',
};

/** The same variables the server writes into /theme.css, for the live preview. Invalid colours are skipped. */
export function previewVars(tokens: Tokens, headingStack: string, bodyStack: string): Record<string, string> {
    const vars: Record<string, string> = {};
    for (const t of TOKENS) if (isHex(tokens[t])) vars[VARS[t]] = tokens[t];
    if (isHex(tokens.primary)) vars['--site-on-primary'] = onColor(tokens.primary);
    vars['--site-font-heading'] = headingStack;
    vars['--site-font-body'] = bodyStack;
    return vars;
}

export interface ContrastIssue {
    label: string;
    ratio: number;
}

/** WCAG AA (4.5:1) checks that matter for reading the site. Advisory: saving is still allowed. */
export function contrastIssues(t: Tokens): ContrastIssue[] {
    const pairs: [string, Token | string, Token | string][] = [
        ['Text on content surface', 'text', 'surface'],
        ['Text on page background', 'text', 'background'],
        ['Muted text on content surface', 'muted', 'surface'],
        ['Links on content surface', 'link', 'surface'],
        ['Button text on primary', '__on_primary', 'primary'],
    ];

    return pairs.flatMap(([label, fg, bg]) => {
        const f = fg === '__on_primary' ? (isHex(t.primary) ? onColor(t.primary) : '') : t[fg as Token];
        const b = t[bg as Token];
        if (!isHex(f) || !isHex(b)) return [];
        const ratio = contrast(f, b);
        return ratio < 4.5 ? [{ label, ratio: Math.round(ratio * 100) / 100 }] : [];
    });
}
