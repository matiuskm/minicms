import { Link } from '@inertiajs/react';

export interface Card {
    id: number;
    title: string;
    url: string;
    excerpt: string | null;
    date: string;
    date_long: string;
    date_iso: string;
    reading_minutes: number;
    category: { name: string; url: string };
    author: { name: string; url: string };
    image: { large: string; medium: string; thumb: string; alt: string | null } | null;
    tags?: { name: string; url: string }[];
}

export function Meta({ post }: { post: Pick<Card, 'date' | 'reading_minutes'> }) {
    return (
        <div className="site-meta">
            <span>{post.date}</span>
            <i />
            <span>{post.reading_minutes} min read</span>
        </div>
    );
}

const MAX_TAGS = 3;

/** `list` is the one-card-per-row layout: the full date and the technology tags show, with the overflow as "+N". */
export function PostCard({ post, list = false }: { post: Card; list?: boolean }) {
    const image = post.image ? (
        <img className="site-card-image" src={post.image.medium} alt={post.image.alt ?? ''} loading="lazy" />
    ) : (
        <div className="site-card-image" aria-hidden />
    );
    const tags = post.tags ?? [];
    const body = (
        <>
            <div>
                <Link href={post.category.url} className="site-pill">
                    {post.category.name}
                </Link>
            </div>
            <Link href={post.url}>
                <h3>{post.title}</h3>
            </Link>
            {post.excerpt && <p>{post.excerpt}</p>}
            {list && tags.length > 0 && (
                <div className="site-card-tags">
                    {tags.slice(0, MAX_TAGS).map((t) => (
                        <Link key={t.url} href={t.url}>
                            {t.name}
                        </Link>
                    ))}
                    {tags.length > MAX_TAGS && <span>+{tags.length - MAX_TAGS}</span>}
                </div>
            )}
            <Meta post={list ? { ...post, date: post.date_long } : post} />
        </>
    );

    return (
        <article className="site-card">
            {image}
            {list ? <div className="site-card-body">{body}</div> : body}
        </article>
    );
}
