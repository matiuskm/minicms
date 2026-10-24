import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@inertiajs/react', async (orig) => ({
    ...(await orig<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { errors: {}, auth: { user: { role: 'admin' } } } }),
    router: { post: (...a: unknown[]) => post(...a), delete: vi.fn(), put: vi.fn(), get: vi.fn() },
    Head: () => null,
}));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('../../../css/fonts/all.css', () => ({}));

import Appearance from './appearance';

const base = {
    background: '#f1e5da',
    surface: '#ffffff',
    text: '#1d1b19',
    muted: '#7a726b',
    primary: '#8f3f1f',
    accent: '#f7ede5',
    border: '#ece4dc',
    link: '#8f3f1f',
};
const palettes = [
    { id: 1, name: 'Light Classic', is_preset: true, is_active: true, tokens: base },
    { id: 2, name: 'Dark', is_preset: true, is_active: false, tokens: { ...base, background: '#0f1115', surface: '#181b21', text: '#eceff4' } },
    { id: 3, name: 'Mine', is_preset: false, is_active: false, tokens: base },
];
const fonts = ['system-sans', 'system-serif', 'monospace', 'open-sans', 'roboto', 'lato', 'merriweather', 'source-serif'].map((key) => ({
    key,
    label: key,
    stack: key,
}));

beforeEach(() => {
    post.mockClear();
    (globalThis as unknown as { route: (n: string, p?: unknown) => string }).route = (name, p) =>
        `${name}:${Array.isArray(p) ? p.join(',') : (p ?? '')}`;
});

const renderPage = () =>
    render(<Appearance palettes={palettes} fonts={fonts} typography={{ heading: 'system-sans', body: 'system-sans', size: 'm' }} maxCustom={20} />);

describe('Appearance page', () => {
    it('lists palettes, marks the live one, and offers the preview', () => {
        renderPage();

        expect(screen.getByText('Light Classic')).toBeInTheDocument();
        expect(screen.getAllByText('Live')).toHaveLength(1);
        expect(screen.getAllByText('Built-in')).toHaveLength(2);
        expect(screen.getByLabelText('Preview of the public site')).toBeInTheDocument();
        expect(screen.getByText('Contrast looks good (AA).')).toBeInTheDocument();
    });

    it('activates a palette through the server', () => {
        renderPage();

        fireEvent.click(screen.getAllByRole('button', { name: /use/i })[0]);

        expect(post).toHaveBeenCalledWith('admin.appearance.palettes.activate:2', {}, expect.anything());
    });

    it('only lets custom palettes be edited or deleted', () => {
        renderPage();

        expect(screen.queryByRole('button', { name: 'Edit Dark' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Delete Light Classic' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Edit Mine' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete Mine' })).toBeInTheDocument();
    });

    it('warns about low contrast while editing, without blocking the save', () => {
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Mine' }));

        const editor = screen.getByLabelText('Palette editor');
        fireEvent.change(within(editor).getByLabelText('Text'), { target: { value: '#e6e0d8' } });

        const alert = screen.getByRole('alert');
        expect(alert).toHaveTextContent('Text on content surface');
        expect(alert).toHaveTextContent('can still save');
        expect(within(editor).getByRole('button', { name: 'Save palette' })).toBeEnabled();
    });

    it('offers the eight fonts and three sizes', () => {
        renderPage();

        expect(within(screen.getByLabelText('Heading font')).getAllByRole('option')).toHaveLength(8);
        expect(screen.getAllByRole('radio')).toHaveLength(3);
    });
});
