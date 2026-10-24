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

export function PostCard({ post }: { post: Card }) {
    return (
        <article className="site-card">
            {post.image ? (
                <img className="site-card-image" src={post.image.medium} alt={post.image.alt ?? ''} loading="lazy" />
            ) : (
                <div className="site-card-image" aria-hidden />
            )}
            <div>
                <Link href={post.category.url} className="site-pill">
                    {post.category.name}
                </Link>
            </div>
            <Link href={post.url}>
                <h3>{post.title}</h3>
            </Link>
            {post.excerpt && <p>{post.excerpt}</p>}
            <Meta post={post} />
        </article>
    );
}
