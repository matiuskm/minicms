import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { SidebarProvider } from '@/components/ui/sidebar';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Ada Admin', email: 'ada@example.com', role: 'admin', display_name: null } } } }),
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => <a {...props}>{children}</a>,
}));

import { AppSidebarHeader } from './app-sidebar-header';

describe('AppSidebarHeader', () => {
    it('offers a Visit Website link that opens the public site in a new tab', () => {
        render(
            <SidebarProvider>
                <AppSidebarHeader breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]} />
            </SidebarProvider>,
        );

        const link = screen.getByRole('link', { name: /visit website/i });
        expect(link).toHaveAttribute('href', '/');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link.getAttribute('rel')).toContain('noopener');
        expect(screen.getByRole('button', { name: 'Account menu' })).toBeInTheDocument();
    });
});
