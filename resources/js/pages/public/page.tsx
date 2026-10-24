import { PublicLayout, type Site } from '@/components/public/layout';
import { type Seo } from '@/components/public/seo';

interface Props {
    site: Site;
    seo: Seo;
    page: { title: string; body: string };
}

export default function Page({ site, seo, page }: Props) {
    return (
        <PublicLayout site={site} seo={seo}>
            <article className="site-article">
                <h1>{page.title}</h1>
                <div className="site-prose" dangerouslySetInnerHTML={{ __html: page.body }} />
            </article>
        </PublicLayout>
    );
}
