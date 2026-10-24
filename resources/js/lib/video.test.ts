import { describe, expect, it } from 'vitest';

import { embedSrc, parseVideoUrl } from './video';

describe('parseVideoUrl', () => {
    it.each([
        ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', { provider: 'youtube', id: 'dQw4w9WgXcQ' }],
        ['https://youtu.be/dQw4w9WgXcQ?t=5', { provider: 'youtube', id: 'dQw4w9WgXcQ' }],
        ['https://www.youtube.com/embed/dQw4w9WgXcQ', { provider: 'youtube', id: 'dQw4w9WgXcQ' }],
        ['https://vimeo.com/76979871', { provider: 'vimeo', id: '76979871' }],
        ['https://player.vimeo.com/video/76979871', { provider: 'vimeo', id: '76979871' }],
    ])('accepts %s', (url, expected) => {
        expect(parseVideoUrl(url)).toEqual(expected);
    });

    it.each([
        'javascript:alert(1)',
        'https://evil.example/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v="><script>',
        'not a url',
        '',
        'https://vimeo.com/abc',
    ])('rejects %s', (url) => {
        expect(parseVideoUrl(url)).toBeNull();
    });

    it('always builds embeds from fixed hosts', () => {
        expect(embedSrc({ provider: 'youtube', id: 'dQw4w9WgXcQ' })).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
        expect(embedSrc({ provider: 'vimeo', id: '76979871' })).toBe('https://player.vimeo.com/video/76979871');
    });
});
