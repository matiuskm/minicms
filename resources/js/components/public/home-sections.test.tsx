import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => <a {...props}>{children}</a>,
}));

import { HomeSections, type Section } from './home-sections';

const pager = { current: 1, last: 1, prev: null, next: null, pages: [] };
const img = { large: '/a-large.jpg', medium: '/a-medium.jpg', alt: 'A view' };
const slide = (title: string) => ({ image: img, title, text: '', button: null, align: 'left' as const });

describe('HomeSections', () => {
    beforeAll(() => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() });
        Element.prototype.scrollTo = vi.fn();
    });

    it('renders an image hero with a safe external button', () => {
        const sections: Section[] = [
            {
                id: 'a1',
                type: 'image',
                image: img,
                title: 'Welcome',
                text: 'Hello',
                align: 'center',
                button: { label: 'Go', url: 'https://example.com', external: true },
            },
        ];
        render(<HomeSections sections={sections} pager={pager} />);

        expect(screen.getByRole('heading', { name: 'Welcome' })).toBeInTheDocument();
        const link = screen.getByRole('link', { name: 'Go' });
        expect(link.getAttribute('rel')).toContain('noopener');
        expect(screen.getByAltText('A view')).toBeInTheDocument();
    });

    it('renders a slider as a labelled carousel whose controls move between slides', () => {
        const sections: Section[] = [{ id: 's1', type: 'slider', autoplay: true, slides: [slide('One'), slide('Two')] }];
        render(<HomeSections sections={sections} pager={pager} />);

        expect(screen.getByRole('region', { name: 'Highlights' })).toBeInTheDocument();
        expect(screen.getByRole('group', { name: '2 of 2' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next slide' }));
        expect(Element.prototype.scrollTo).toHaveBeenCalled();
    });

    it('shows the empty message only when there is nothing to show', () => {
        render(
            <HomeSections
                sections={[
                    { id: 'f', type: 'featured', featured: null, latest: [] },
                    { id: 'b', type: 'blog', title: '', posts: [] },
                ]}
                pager={pager}
            />,
        );
        expect(screen.getByText('Nothing has been published yet.')).toBeInTheDocument();
    });
});
