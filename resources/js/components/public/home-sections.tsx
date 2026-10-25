import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Pager, type PagerData } from '@/components/public/pager';
import { Meta, PostCard, type Card } from '@/components/public/post-card';

interface Button {
    label: string;
    url: string;
    external: boolean;
}
interface Banner {
    image: { large: string; medium: string; alt: string | null } | null;
    title: string;
    text: string;
    button: Button | null;
    align: 'left' | 'center' | 'right';
}

export type Section =
    | { id: string; type: 'featured'; featured: Card | null; latest: Card[] }
    | ({ id: string; type: 'image' } & Banner)
    | { id: string; type: 'split'; title: string; html: string; image: Banner['image']; side: 'left' | 'right'; button: Button | null }
    | { id: string; type: 'slider'; autoplay: boolean; slides: Banner[] }
    | { id: string; type: 'rich_text'; title: string; html: string }
    | { id: string; type: 'post_grid'; title: string; posts: Card[] }
    | { id: string; type: 'cta'; title: string; text: string; button: Button | null }
    | { id: string; type: 'blog'; title: string; posts: Card[] };

function LinkButton({ button }: { button: Button }) {
    return button.external ? (
        <a className="site-button" href={button.url} rel="noopener noreferrer nofollow">
            {button.label}
        </a>
    ) : (
        <Link className="site-button" href={button.url}>
            {button.label}
        </Link>
    );
}

function Featured({ featured, latest }: { featured: Card | null; latest: Card[] }) {
    if (!featured) return null;

    return (
        <section className="site-hero" aria-label="Featured">
            <Link href={featured.url} className="site-feature">
                {featured.image && <img src={featured.image.large} alt={featured.image.alt ?? ''} />}
                <div className="site-feature-body">
                    <span className="site-pill">{featured.category.name}</span>
                    <h2>{featured.title}</h2>
                    <Meta post={featured} />
                </div>
            </Link>
            {latest.length > 0 && (
                <aside className="site-latest">
                    <h2 className="site-section-title">Latest post</h2>
                    <ul>
                        {latest.map((p) => (
                            <li key={p.id}>
                                <Link href={p.url}>
                                    {p.image ? (
                                        <img className="site-thumb" src={p.image.thumb} alt="" loading="lazy" />
                                    ) : (
                                        <span className="site-thumb" aria-hidden />
                                    )}
                                    <div>
                                        <h3>{p.title}</h3>
                                        <Meta post={p} />
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>
            )}
        </section>
    );
}

function BannerSlide({ b, eager, heading: Heading }: { b: Banner; eager: boolean; heading: 'h1' | 'h2' | 'h3' }) {
    return (
        <div className={`site-banner site-banner-${b.align}`}>
            {b.image && <img src={b.image.large} alt={b.image.alt ?? ''} loading={eager ? 'eager' : 'lazy'} decoding="async" />}
            {(b.title || b.text || b.button) && (
                <div className="site-banner-body">
                    {b.title && <Heading>{b.title}</Heading>}
                    {b.text && <p>{b.text}</p>}
                    {b.button && <LinkButton button={b.button} />}
                </div>
            )}
        </div>
    );
}

function Slider({ slides, autoplay }: { slides: Banner[]; autoplay: boolean }) {
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);

    const go = useCallback(
        (to: number) => {
            const el = track.current;
            if (!el) return;
            const n = (to + slides.length) % slides.length;
            el.scrollTo({ left: n * el.clientWidth, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        },
        [slides.length],
    );

    // Autoplay stops on hover or focus, and never runs for visitors who ask for less motion.
    useEffect(() => {
        if (!autoplay || paused || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const t = setInterval(() => go(index + 1), 6000);
        return () => clearInterval(t);
    }, [autoplay, paused, index, go]);

    return (
        <section
            className="site-slider"
            aria-roledescription="carousel"
            aria-label="Highlights"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocus={() => setPaused(true)}
            onBlur={() => setPaused(false)}
        >
            <div
                ref={track}
                className="site-slider-track"
                tabIndex={0}
                onScroll={(e) => setIndex(Math.round(e.currentTarget.scrollLeft / e.currentTarget.clientWidth))}
            >
                {slides.map((s, i) => (
                    <div key={i} className="site-slide" role="group" aria-roledescription="slide" aria-label={`${i + 1} of ${slides.length}`}>
                        <BannerSlide b={s} eager={i === 0} heading="h2" />
                    </div>
                ))}
            </div>
            <button type="button" className="site-slider-arrow site-slider-prev" aria-label="Previous slide" onClick={() => go(index - 1)}>
                <ChevronLeft />
            </button>
            <button type="button" className="site-slider-arrow site-slider-next" aria-label="Next slide" onClick={() => go(index + 1)}>
                <ChevronRight />
            </button>
            <div className="site-slider-dots">
                {slides.map((_, i) => (
                    <button key={i} type="button" aria-label={`Slide ${i + 1}`} aria-current={i === index} onClick={() => go(i)} />
                ))}
            </div>
        </section>
    );
}

function Grid({ title, posts }: { title: string; posts: Card[] }) {
    if (posts.length === 0) return null;

    return (
        <section aria-label={title || 'Posts'}>
            {title && <h2 className="site-section-title">{title}</h2>}
            <div className="site-grid">
                {posts.map((p) => (
                    <PostCard key={p.id} post={p} />
                ))}
            </div>
        </section>
    );
}

export function HomeSections({ sections, pager }: { sections: Section[]; pager: PagerData }) {
    const hasBlog = sections.some((s) => s.type === 'blog');
    const empty = sections.every((s) => (s.type === 'featured' ? !s.featured : s.type === 'blog' ? s.posts.length === 0 : false));

    return (
        <>
            {sections.map((s, i) => (
                <div key={s.id} className={i > 0 ? 'site-block' : undefined}>
                    {s.type === 'featured' && <Featured featured={s.featured} latest={s.latest} />}
                    {s.type === 'image' && <BannerSlide b={s} eager heading="h2" />}
                    {s.type === 'split' && (
                        <section className={`site-split site-split-${s.side}`}>
                            <div className="site-split-text">
                                {s.title && <h2>{s.title}</h2>}
                                {s.html && <div className="site-prose" dangerouslySetInnerHTML={{ __html: s.html }} />}
                                {s.button && <LinkButton button={s.button} />}
                            </div>
                            {s.image && <img src={s.image.large} alt={s.image.alt ?? ''} loading="lazy" decoding="async" />}
                        </section>
                    )}
                    {s.type === 'slider' && <Slider slides={s.slides} autoplay={s.autoplay} />}
                    {s.type === 'rich_text' && (
                        <section className="site-text-block">
                            {s.title && <h2 className="site-section-title">{s.title}</h2>}
                            <div className="site-prose" dangerouslySetInnerHTML={{ __html: s.html }} />
                        </section>
                    )}
                    {s.type === 'post_grid' && <Grid title={s.title} posts={s.posts} />}
                    {s.type === 'cta' && (
                        <section className="site-callout">
                            <h2>{s.title}</h2>
                            {s.text && <p>{s.text}</p>}
                            {s.button && <LinkButton button={s.button} />}
                        </section>
                    )}
                    {s.type === 'blog' && <Grid title={s.title} posts={s.posts} />}
                </div>
            ))}
            {empty && <p className="site-empty">Nothing has been published yet.</p>}
            {hasBlog && <Pager pager={pager} />}
        </>
    );
}
