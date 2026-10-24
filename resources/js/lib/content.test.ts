import { describe, expect, it } from 'vitest';

import { fromLocalInput, toLocalInput } from './content';

describe('datetime helpers', () => {
    it('round-trips through the browser timezone', () => {
        const iso = '2026-10-25T03:30:00.000Z';
        expect(fromLocalInput(toLocalInput(iso))).toBe(iso);
    });

    it('treats empty values as null', () => {
        expect(toLocalInput(null)).toBe('');
        expect(fromLocalInput('')).toBeNull();
    });
});
