import { Link } from '@inertiajs/react';

import { PublicLayout, type Site } from '@/components/public/layout';
import { type Card } from '@/components/public/post-card';
import { type Seo } from '@/components/public/seo';
import { ShareButtons } from '@/components/public/share-buttons';

interface Props {
    site: Site;
    seo: Seo;
    post: Card & { body: string; tags: { name: string; url: string }[] };
}

export default function Post({ site, seo, post }: Props) {
    return (
        <PublicLayout site={site} seo={seo}>
            <article className="site-article">
                <Link href={post.category.url} className="site-pill">
                    {post.category.name}
                </Link>
                <h1>{post.title}</h1>
                <div className="site-meta">
                    <Link href={post.author.url}>{post.author.name}</Link>
                    <i />
                    <time dateTime={post.date_iso}>{post.date_long}</time>
                    <i />
                    <span>{post.reading_minutes} min read</span>
                </div>
                {post.image && <img className="site-article-image" src={post.image.large} alt={post.image.alt ?? ''} />}
                {/* Sanitised on save and rendered server-side (ContentRenderer): the only HTML we inject. */}
                <div className="site-prose" dangerouslySetInnerHTML={{ __html: post.body }} />
                {post.tags.length > 0 && (
                    <div className="site-tags" aria-label="Tags">
                        {post.tags.map((t) => (
                            <Link key={t.url} href={t.url} className="site-pill">
                                {t.name}
                            </Link>
                        ))}
                    </div>
                )}
                <ShareButtons url={seo.canonical} title={post.title} />
            </article>
        </PublicLayout>
    );
}
