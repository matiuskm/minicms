import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ShareButtons, tagged } from './share-buttons';

const url = 'https://example.com/blog/hello';

describe('ShareButtons', () => {
    it('tags the url with the network as utm_source', () => {
        expect(tagged(url, 'whatsapp')).toBe('https://example.com/blog/hello?utm_source=whatsapp&utm_medium=social&utm_campaign=share');
    });

    it('opens each network share dialog with its own utm_source, safely in a new tab', () => {
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        render(<ShareButtons url={url} title="Hello & welcome" />);

        fireEvent.click(screen.getByRole('button', { name: 'WhatsApp' }));
        const [wa, target, features] = open.mock.calls[0];
        expect(String(wa)).toContain('https://wa.me/');
        expect(decodeURIComponent(String(wa))).toContain('utm_source=whatsapp');
        expect(String(wa)).toContain(encodeURIComponent('Hello & welcome'));
        expect([target, features]).toEqual(['_blank', 'noopener,noreferrer']);

        fireEvent.click(screen.getByRole('button', { name: 'Telegram' }));
        expect(decodeURIComponent(String(open.mock.calls[1][0]))).toContain('utm_source=telegram');
    });

    it('copies the instagram-tagged link to the clipboard', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });
        render(<ShareButtons url={url} title="Hello" />);

        fireEvent.click(screen.getByRole('button', { name: 'Instagram' }));

        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('utm_source=instagram'));
        expect(await screen.findByRole('button', { name: /Link copied/ })).toBeTruthy();
    });
});
