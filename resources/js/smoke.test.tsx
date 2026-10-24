import { render, screen } from '@testing-library/react';
import { expect, test } from 'vitest';

test('vitest + testing-library run', () => {
    render(<p>ok</p>);
    expect(screen.getByText('ok')).toBeInTheDocument();
});
