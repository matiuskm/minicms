import { PublicLayout, type Site } from '@/components/public/layout';
import { Pager, type PagerData } from '@/components/public/pager';
import { PostCard, type Card } from '@/components/public/post-card';
import { type Seo } from '@/components/public/seo';

interface Props {
    site: Site;
    seo: Seo;
    heading: string;
    intro: string | null;
    search?: string;
    list?: boolean;
    posts: Card[];
    pager: PagerData | null;
}

export default function Archive({ site, seo, heading, intro, search, list, posts, pager }: Props) {
    return (
        <PublicLayout site={site} seo={seo}>
            <h1 className="site-section-title" style={{ fontSize: '2rem' }}>
                {heading}
            </h1>
            {intro && (
                <p className="site-meta" style={{ marginBottom: '1.5rem', fontSize: '0.9rem' }}>
                    {intro}
                </p>
            )}

            {search !== undefined && (
                <form action="/search" method="get" className="site-search" role="search">
                    <input type="search" name="q" defaultValue={search} placeholder="Search posts" aria-label="Search posts" maxLength={100} />
                    <button type="submit">Search</button>
                </form>
            )}

            {posts.length > 0 ? (
                <div className={list ? 'site-grid site-grid-list' : 'site-grid'}>
                    {posts.map((p) => (
                        <PostCard key={p.id} post={p} list={list} />
                    ))}
                </div>
            ) : (
                <p className="site-empty">{search ? 'No posts matched your search.' : 'No posts here yet.'}</p>
            )}
            <Pager pager={pager} />
        </PublicLayout>
    );
}
