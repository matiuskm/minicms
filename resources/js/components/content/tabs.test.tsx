import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Tabs } from './tabs';

describe('Tabs', () => {
    it('shows one panel at a time but keeps all mounted', () => {
        render(
            <Tabs
                tabs={[
                    { id: 'a', label: 'Content', content: <p>first</p> },
                    { id: 'b', label: 'SEO', content: <p>second</p> },
                ]}
            />,
        );

        expect(screen.getByRole('tab', { name: 'Content' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByText('first')).toBeVisible();
        expect(screen.getByText('second')).not.toBeVisible();

        fireEvent.click(screen.getByRole('tab', { name: 'SEO' }));
        expect(screen.getByText('second')).toBeVisible();
        expect(screen.getByText('first')).not.toBeVisible();
    });
});
