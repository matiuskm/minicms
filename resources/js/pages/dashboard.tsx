import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ExternalLink, FilePlus2, ShieldAlert } from 'lucide-react';

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

interface Props {
    counts: Record<string, number>;
    queue: { id: number; title: string; author: string; note: string | null; updated_at: string }[];
    activity?: { id: number; action: string; actor: string | null; at: string }[];
    failedLogins24h?: number;
    integrity?: { at: string; mismatches: number } | null;
    backup?: { at: string; ok: boolean } | null;
    lockdown?: boolean;
}

const when = (iso: string) => new Date(iso).toLocaleString();

function Health({ ok, label, detail, href }: { ok: boolean; label: string; detail: string; href?: string }) {
    const Icon = ok ? CheckCircle2 : AlertTriangle;
    const body = (
        <div className={`flex items-start gap-3 rounded-xl border p-4 shadow-xs ${ok ? 'bg-card' : 'border-amber-300 bg-amber-50'}`}>
            <Icon className={`mt-0.5 size-5 shrink-0 ${ok ? 'text-emerald-600' : 'text-amber-600'}`} />
            <div>
                <p className="text-sm font-medium">{label}</p>
                <p className="text-muted-foreground text-xs">{detail}</p>
            </div>
        </div>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}

export default function Dashboard({ counts, queue, activity, failedLogins24h, integrity, backup, lockdown }: Props) {
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

                {lockdown && (
                    <div role="alert" className="flex items-center gap-2 rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                        <ShieldAlert className="size-4" /> Lockdown is on: only admins can sign in.{' '}
                        <Link href={route('admin.security.index')} className="underline">
                            Manage
                        </Link>
                    </div>
                )}

                <section aria-label="Posts" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {LABELS.map(([status, label]) => (
                        <Link
                            key={status}
                            href={route('admin.posts.index', { status })}
                            className="bg-card hover:border-primary rounded-xl border p-4 shadow-xs"
                        >
                            <p className="text-muted-foreground text-xs">{label}</p>
                            <p className="mt-1 text-3xl font-semibold">{counts[status] ?? 0}</p>
                        </Link>
                    ))}
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="bg-card rounded-xl border p-4 shadow-xs" aria-label={isAdmin ? 'Review queue' : 'Sent back to you'}>
                        <h2 className="mb-3 font-semibold">{isAdmin ? 'Review queue' : 'Sent back to you'}</h2>
                        {queue.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{isAdmin ? 'Nothing is waiting for review.' : 'No posts were sent back.'}</p>
                        ) : (
                            <ul className="divide-y">
                                {queue.map((p) => (
                                    <li key={p.id} className="py-2 text-sm">
                                        <Link href={route('admin.posts.edit', p.id)} className="font-medium hover:underline">
                                            {p.title}
                                        </Link>
                                        <p className="text-muted-foreground text-xs">
                                            {p.author} · {when(p.updated_at)}
                                            {p.note && ` · “${p.note}”`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {isAdmin && activity && (
                        <section className="bg-card rounded-xl border p-4 shadow-xs" aria-label="Recent activity">
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="font-semibold">Recent activity</h2>
                                <Link href={route('admin.audit-logs.index')} className="text-primary text-xs hover:underline">
                                    Audit log
                                </Link>
                            </div>
                            <ul className="divide-y">
                                {activity.map((a) => (
                                    <li key={a.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                        <span className="font-mono text-xs">{a.action}</span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {a.actor ?? 'system'} · {when(a.at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                {isAdmin && (
                    <section aria-label="Site health" className="grid gap-4 md:grid-cols-3">
                        <Health
                            ok={(failedLogins24h ?? 0) < 20}
                            label={`${failedLogins24h ?? 0} failed sign-ins in 24 h`}
                            detail="Unusual spikes can mean someone is guessing passwords."
                            href={route('admin.audit-logs.index', { action: 'login.failed' })}
                        />
                        <Health
                            ok={!!integrity && integrity.mismatches === 0}
                            label={
                                integrity
                                    ? integrity.mismatches === 0
                                        ? 'Content integrity OK'
                                        : `${integrity.mismatches} item(s) changed outside the CMS`
                                    : 'Integrity not checked yet'
                            }
                            detail={integrity ? `Last check ${when(integrity.at)}` : 'Runs every 15 minutes once the scheduler is on.'}
                            href={route('admin.security.index')}
                        />
                        <Health
                            ok={!!backup && backup.ok}
                            label={backup ? (backup.ok ? 'Backup OK' : 'Last backup failed') : 'No backup yet'}
                            detail={backup ? `Last run ${when(backup.at)}` : 'Backups run daily once the scheduler is on.'}
                            href={route('admin.security.index')}
                        />
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
