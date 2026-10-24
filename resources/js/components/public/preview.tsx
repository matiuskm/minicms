import { type CSSProperties } from 'react';

import { previewVars, type Tokens } from '@/lib/theme';

/** A small stand-in for the public home page, styled by the real `.site` CSS with the palette applied inline. */
export function SitePreview({
    tokens,
    headingStack,
    bodyStack,
    scale = 1,
}: {
    tokens: Tokens;
    headingStack: string;
    bodyStack: string;
    scale?: number;
}) {
    const style = { ...previewVars(tokens, headingStack, bodyStack), fontSize: `${scale * 100}%`, minHeight: 0 } as CSSProperties;

    return (
        <div className="site rounded-xl" style={{ ...style, padding: '0.75rem' }} aria-label="Preview of the public site">
            <div className="site-shell" style={{ padding: '1rem 1.25rem 1.25rem', borderRadius: '1.25rem' }}>
                <div className="site-header" style={{ paddingBottom: '1rem' }}>
                    <span className="site-logo" style={{ fontSize: '1.1rem' }}>
                        Your site
                    </span>
                    <nav className="site-nav" style={{ gap: '1rem' }}>
                        <span>About</span>
                        <span>Blog</span>
                    </nav>
                    <span className="site-cta" style={{ padding: '0.4rem 0.8rem' }}>
                        Get started
                    </span>
                </div>
                <div className="site-hero" style={{ gridTemplateColumns: '1.4fr 1fr', gap: '1rem' }}>
                    <div className="site-feature" style={{ minHeight: '9rem', borderRadius: '1rem' }}>
                        <div className="site-feature-body" style={{ padding: '1.5rem 1rem 1rem' }}>
                            <span className="site-pill">Category</span>
                            <h2 style={{ fontSize: '1.05rem', margin: '0.5rem 0 0.25rem' }}>A headline that sets the tone</h2>
                            <div className="site-meta">
                                <span>Aug 10</span>
                                <i />
                                <span>5 min read</span>
                            </div>
                        </div>
                    </div>
                    <div className="site-latest">
                        <h2 className="site-section-title" style={{ fontSize: '0.95rem' }}>
                            Latest post
                        </h2>
                        {[1, 2].map((n) => (
                            <div key={n} className="flex items-center gap-2" style={{ marginBottom: '0.6rem' }}>
                                <span className="site-thumb" style={{ width: '2rem', height: '2rem' }} />
                                <div>
                                    <p style={{ fontSize: '0.7rem', margin: 0 }}>A supporting story number {n}</p>
                                    <div className="site-meta" style={{ fontSize: '0.6rem' }}>
                                        <span>Aug 9</span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
                <hr className="site-divider" style={{ margin: '1rem 0' }} />
                <div className="site-grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: '0.6rem' }}>
                    {[1, 2, 3].map((n) => (
                        <div key={n} className="site-card">
                            <div className="site-card-image" style={{ aspectRatio: '16 / 8' }} />
                            <h3 style={{ fontSize: '0.78rem' }}>Card title {n}</h3>
                            <p style={{ fontSize: '0.65rem' }}>
                                Body text in the chosen font, with a <a style={{ color: 'var(--site-link)', textDecoration: 'underline' }}>link</a>{' '}
                                and muted details.
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
