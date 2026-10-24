import { Head } from '@inertiajs/react';

export interface Seo {
    title: string;
    description: string | null;
    canonical: string;
    image: string | null;
    type: 'website' | 'article';
    noindex: boolean;
    published?: string;
    modified?: string;
    author?: string;
}

export function SeoHead({ seo, siteName }: { seo: Seo; siteName: string }) {
    return (
        <Head title={seo.title}>
            {seo.description && <meta name="description" content={seo.description} />}
            <link rel="canonical" href={seo.canonical} />
            {seo.noindex && <meta name="robots" content="noindex, follow" />}
            <meta property="og:site_name" content={siteName} />
            <meta property="og:type" content={seo.type} />
            <meta property="og:title" content={seo.title} />
            {seo.description && <meta property="og:description" content={seo.description} />}
            <meta property="og:url" content={seo.canonical} />
            {seo.image && <meta property="og:image" content={seo.image} />}
            <meta name="twitter:card" content={seo.image ? 'summary_large_image' : 'summary'} />
            {seo.type === 'article' && seo.published && <meta property="article:published_time" content={seo.published} />}
            {seo.type === 'article' && seo.modified && <meta property="article:modified_time" content={seo.modified} />}
            <link rel="alternate" type="application/rss+xml" title={siteName} href="/rss.xml" />
        </Head>
    );
}
