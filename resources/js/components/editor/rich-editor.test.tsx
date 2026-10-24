import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { RichEditor, stats } from './rich-editor';

describe('stats', () => {
    it('counts words and reading time', () => {
        expect(stats('<p>one two three</p>')).toEqual({ words: 3, minutes: 1 });
        expect(stats(`<p>${'word '.repeat(450)}</p>`).minutes).toBe(3);
    });

    it('counts CJK characters individually', () => {
        expect(stats('<p>日本語</p>').words).toBe(3);
    });
});

describe('RichEditor', () => {
    it('renders the toolbar and initial content', async () => {
        render(<RichEditor value="<p>Hello world</p>" onChange={vi.fn()} />);

        await waitFor(() => expect(screen.getByText('Hello world')).toBeInTheDocument());
        expect(screen.getByRole('toolbar', { name: 'Formatting' })).toBeInTheDocument();
        for (const label of [
            'Bold',
            'Italic',
            'Link',
            'Quote',
            'Inline code',
            'Code block',
            'Table',
            'Insert image',
            'Insert PDF link',
            'Embed video',
        ]) {
            expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
        }
    });

    it('only offers HTML source editing to admins', async () => {
        const { rerender } = render(<RichEditor value="<p>x</p>" onChange={vi.fn()} />);
        await waitFor(() => screen.getByRole('toolbar'));
        expect(screen.queryByRole('button', { name: 'HTML' })).toBeNull();

        rerender(<RichEditor value="<p>x</p>" onChange={vi.fn()} canEditSource />);
        expect(screen.getByRole('button', { name: 'HTML' })).toBeInTheDocument();
    });

    it('shows word count and reading time', async () => {
        render(<RichEditor value="<p>alpha beta</p>" onChange={vi.fn()} />);

        await waitFor(() => expect(screen.getByText('2 words')).toBeInTheDocument());
        expect(screen.getByText('1 min read')).toBeInTheDocument();
    });
});
