const escapeHtml = (s: string) =>
    s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);

/** Adds the site name unless the title already has it. Inertia hands over (and expects back) an HTML-escaped title. */
export function pageTitle(title: string, siteName: string): string {
    const name = escapeHtml(siteName);

    return title.includes(name) ? title : `${title} - ${name}`;
}
