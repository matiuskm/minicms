import { describe, expect, it } from 'vitest';

import { contrast, isHex, normalizeHex, onColor } from './color';
import { contrastIssues, previewVars, type Tokens } from './theme';

const good: Tokens = {
    background: '#f1e5da',
    surface: '#ffffff',
    text: '#1d1b19',
    muted: '#7a726b',
    primary: '#8f3f1f',
    accent: '#f7ede5',
    border: '#ece4dc',
    link: '#8f3f1f',
};

describe('color', () => {
    it('computes WCAG contrast', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 0);
        expect(contrast('#777777', '#777777')).toBeCloseTo(1, 5);
        expect(contrast('#767676', '#ffffff')).toBeGreaterThan(4.5);
    });

    it('accepts only 6 or 8 digit hex', () => {
        for (const ok of ['#aabbcc', '#AABBCC', '#aabbccdd']) expect(isHex(ok)).toBe(true);
        for (const bad of ['#abc', 'aabbcc', 'red', '#12345', '#gggggg', 'red;}body{display:none', 'url(x)', '#aabbcc;'])
            expect(isHex(bad)).toBe(false);
    });

    it('expands 3 digit shorthand for convenience', () => {
        expect(normalizeHex('#ABC')).toBe('#aabbcc');
        expect(normalizeHex(' #aabbcc ')).toBe('#aabbcc');
    });

    it('picks readable text for a background', () => {
        expect(onColor('#111111')).toBe('#ffffff');
        expect(onColor('#fafafa')).toBe('#111111');
    });
});

describe('theme', () => {
    it('has no contrast issues for the default palette', () => {
        expect(contrastIssues(good)).toEqual([]);
    });

    it('warns when text is hard to read', () => {
        const issues = contrastIssues({ ...good, text: '#d0c8c0', link: '#e8e0d8' });
        expect(issues.map((i) => i.label)).toEqual(expect.arrayContaining(['Text on content surface', 'Links on content surface']));
        expect(issues[0].ratio).toBeLessThan(4.5);
    });

    it('builds preview variables and skips invalid colours', () => {
        const vars = previewVars({ ...good, text: 'red;}body{display:none' }, 'Georgia, serif', 'sans-serif');
        expect(vars['--site-bg']).toBe('#f1e5da');
        expect(vars['--site-text']).toBeUndefined();
        expect(vars['--site-font-heading']).toBe('Georgia, serif');
        expect(vars['--site-on-primary']).toBe('#ffffff');
    });
});
