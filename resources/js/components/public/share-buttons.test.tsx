import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ShareButtons, tagged } from './share-buttons';

const url = 'https://example.com/blog/hello';

describe('ShareButtons', () => {
    it('tags the url with the network as utm_source', () => {
        expect(tagged(url, 'whatsapp')).toBe('https://example.com/blog/hello?utm_source=whatsapp&utm_medium=social&utm_campaign=share');
    });

    it('links each network with its own utm_source, opening safely in a new tab', () => {
        render(<ShareButtons url={url} title="Hello & welcome" />);

        const wa = screen.getByRole('link', { name: 'WhatsApp' });
        expect(decodeURIComponent(wa.getAttribute('href')!)).toContain('utm_source=whatsapp');
        expect(wa.getAttribute('href')).toContain(encodeURIComponent('Hello & welcome'));
        expect(wa).toHaveAttribute('rel', 'noopener noreferrer');
        expect(wa).toHaveAttribute('target', '_blank');
        expect(decodeURIComponent(screen.getByRole('link', { name: 'Telegram' }).getAttribute('href')!)).toContain('utm_source=telegram');
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
