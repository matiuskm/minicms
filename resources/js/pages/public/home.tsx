import { HomeSections, type Section } from '@/components/public/home-sections';
import { PublicLayout, type Site } from '@/components/public/layout';
import { type PagerData } from '@/components/public/pager';
import { type Seo } from '@/components/public/seo';

interface Props {
    site: Site;
    seo: Seo;
    sections: Section[];
    pager: PagerData;
}

export default function Home({ site, seo, sections, pager }: Props) {
    return (
        <PublicLayout site={site} seo={seo}>
            <HomeSections sections={sections} pager={pager} />
        </PublicLayout>
    );
}
