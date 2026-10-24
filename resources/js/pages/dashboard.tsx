import { Head, Link, usePage } from '@inertiajs/react';
import { ExternalLink, FilePlus2, Newspaper } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const LABELS: [string, string][] = [
    ['pending_review', 'Waiting for review'],
    ['draft', 'Drafts'],
    ['scheduled', 'Scheduled'],
    ['published', 'Published'],
];

export default function Dashboard({ counts }: { counts: Record<string, number> }) {
    const { auth } = usePage<SharedData>().props;
    const isAdmin = auth.user.role === 'admin';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <div className="bg-card flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5 shadow-xs">
                    <div>
                        <h1 className="text-xl font-semibold">Welcome back, {auth.user.name.split(' ')[0]}</h1>
                        <p className="text-muted-foreground text-sm">See your changes on the live site, or keep writing.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a href="/" target="_blank" rel="noopener">
                                Visit Website <ExternalLink className="size-4" />
                            </a>
                        </Button>
                        <Button asChild>
                            <Link href={route('admin.posts.create')}>
                                <FilePlus2 className="size-4" /> Write a post
                            </Link>
                        </Button>
                    </div>
                </div>

                <section aria-label="Your posts" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {LABELS.map(([status, label]) => (
                        <Link
                            key={status}
                            href={route('admin.posts.index', { status })}
                            className="bg-card hover:border-primary rounded-xl border p-4 shadow-xs"
                        >
                            <p className="text-muted-foreground text-xs">{isAdmin || status === 'pending_review' ? label : label}</p>
                            <p className="mt-1 text-3xl font-semibold">{counts[status] ?? 0}</p>
                        </Link>
                    ))}
                </section>

                <Button asChild variant="ghost">
                    <Link href={route('admin.posts.index')}>
                        <Newspaper className="size-4" /> All posts
                    </Link>
                </Button>
            </div>
        </AppLayout>
    );
}
