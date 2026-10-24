import { Head, Link } from '@inertiajs/react';

import { PublicLayout, type Site } from '@/components/public/layout';

const COPY: Record<number, [string, string]> = {
    403: ['Not allowed', 'You do not have access to this page.'],
    404: ['Page not found', 'The page you are looking for does not exist or has moved.'],
    500: ['Something went wrong', 'An unexpected error occurred. Please try again in a moment.'],
    503: ['Service unavailable', 'We are doing some work and will be back shortly.'],
};

export default function ErrorPage({ status, message, site }: { status: number; message: string | null; site: Site | null }) {
    const [title, text] =
        message === 'maintenance'
            ? ['We’ll be back soon', 'The site is down for maintenance. Please check back shortly.']
            : (COPY[status] ?? COPY[500]);

    return (
        <PublicLayout site={site}>
            <Head title={title}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="site-empty">
                <p className="site-meta" style={{ justifyContent: 'center' }}>
                    {status}
                </p>
                <h1 className="site-section-title" style={{ fontSize: '2rem' }}>
                    {title}
                </h1>
                <p>{text}</p>
                {status !== 503 && (
                    <p style={{ marginTop: '1.5rem' }}>
                        <Link href="/" className="site-cta">
                            Back to home
                        </Link>
                    </p>
                )}
            </div>
        </PublicLayout>
    );
}
