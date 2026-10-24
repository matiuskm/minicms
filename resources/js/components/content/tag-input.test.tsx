import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TagInput } from './tag-input';

afterEach(() => vi.unstubAllGlobals());

describe('TagInput', () => {
    it('creates a tag on Enter via find-or-create and adds a chip', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ status: 201, json: async () => ({ id: 7, name: 'Laravel' }) });
        vi.stubGlobal('fetch', fetchMock);
        const onChange = vi.fn();

        render(<TagInput value={[]} onChange={onChange} />);
        const input = screen.getByLabelText('Add tag');
        fireEvent.change(input, { target: { value: 'Laravel' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        await waitFor(() => expect(onChange).toHaveBeenCalledWith([{ id: 7, name: 'Laravel' }]));
        expect(fetchMock).toHaveBeenCalledWith('/tags/find-or-create', expect.objectContaining({ method: 'POST' }));
    });

    it('does not add a tag that is already selected', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ status: 200, json: async () => ({ id: 7, name: 'Laravel' }) }));
        const onChange = vi.fn();

        render(<TagInput value={[{ id: 7, name: 'Laravel' }]} onChange={onChange} />);
        const input = screen.getByLabelText('Add tag');
        fireEvent.change(input, { target: { value: 'laravel' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(onChange).not.toHaveBeenCalled();
    });

    it('removes a chip and hides the input when disabled', () => {
        const onChange = vi.fn();
        const { rerender } = render(<TagInput value={[{ id: 1, name: 'PHP' }]} onChange={onChange} />);

        fireEvent.click(screen.getByRole('button', { name: 'Remove PHP' }));
        expect(onChange).toHaveBeenCalledWith([]);

        rerender(<TagInput value={[{ id: 1, name: 'PHP' }]} onChange={onChange} disabled />);
        expect(screen.queryByLabelText('Add tag')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove PHP' })).toBeNull();
    });
});
