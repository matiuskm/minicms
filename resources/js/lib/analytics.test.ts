import { describe, expect, it } from 'vitest';

import { buildLink, sourceLabel } from './analytics';

describe('buildLink', () => {
    it('tags a post link with the channel', () => {
        expect(buildLink('https://example.com', '/blog/hello', 'instagram')).toBe(
            'https://example.com/blog/hello?utm_source=instagram&utm_medium=social',
        );
    });

    it('adds a tidy campaign name', () => {
        expect(buildLink('https://example.com/', '/', 'whatsapp', 'Launch Week #1!')).toBe(
            'https://example.com/?utm_source=whatsapp&utm_medium=social&utm_campaign=launch-week-1',
        );
    });

    it('keeps junk out of the query string', () => {
        const link = buildLink('https://example.com', '/x', 'a&b=c#d', '"><script>');
        expect(link).not.toMatch(/[<>"#]/);
        expect(new URL(link).searchParams.get('utm_source')).toBe('abcd');
    });

    it('falls back when the channel is empty', () => {
        expect(new URL(buildLink('https://example.com', '/', '')).searchParams.get('utm_source')).toBe('link');
    });
});

describe('sourceLabel', () => {
    it('names known sources and capitalises others', () => {
        expect(sourceLabel('x')).toBe('X (Twitter)');
        expect(sourceLabel('direct')).toBe('Direct / unknown');
        expect(sourceLabel('telegram')).toBe('Telegram');
    });
});
