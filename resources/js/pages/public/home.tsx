import { Link } from '@inertiajs/react';

import { PublicLayout, type Site } from '@/components/public/layout';
import { Pager, type PagerData } from '@/components/public/pager';
import { Meta, PostCard, type Card } from '@/components/public/post-card';
import { type Seo } from '@/components/public/seo';

interface Props {
    site: Site;
    seo: Seo;
    featured: Card | null;
    latest: Card[];
    posts: Card[];
    pager: PagerData;
}

export default function Home({ site, seo, featured, latest, posts, pager }: Props) {
    return (
        <PublicLayout site={site} seo={seo}>
            {featured && (
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
            )}

            {!featured && posts.length === 0 && <p className="site-empty">Nothing has been published yet.</p>}

            {posts.length > 0 && (
                <>
                    {featured && <hr className="site-divider" />}
                    <section aria-label="More posts">
                        <h2 className="site-section-title">{featured ? 'More posts' : 'Posts'}</h2>
                        <div className="site-grid">
                            {posts.map((p) => (
                                <PostCard key={p.id} post={p} />
                            ))}
                        </div>
                    </section>
                    <hr className="site-divider" style={{ marginBottom: 0 }} />
                    <Pager pager={pager} />
                </>
            )}
        </PublicLayout>
    );
}
