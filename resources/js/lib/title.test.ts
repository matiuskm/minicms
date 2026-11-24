import { escape } from 'es-toolkit/compat';
import { expect, test } from 'vitest';
import { pageTitle } from './title';

test.each(["Matius'", 'A & B', 'Say "hi"', '<3', 'Plain'])('the site name %s is added once', (name) => {
    expect(pageTitle(escape(`${name} | Blog`), name)).toBe(escape(`${name} | Blog`));
    expect(pageTitle('Posts', name)).toBe(`Posts - ${escape(name)}`);
});
