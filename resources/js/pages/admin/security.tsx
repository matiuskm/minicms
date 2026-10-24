import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ShieldAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Security', href: '/admin/security' }];

interface Props {
    lockdown: boolean;
    integrity: { at: string; checked: number; mismatches: { type: 'post' | 'page'; id: number; title: string }[] } | null;
    backup: { at: string; ok: boolean; files: string[]; bytes: number; error: string | null } | null;
    backupSets: string[];
    failedLogins24h: number;
    lockouts24h: number;
}

const when = (iso: string) => new Date(iso).toLocaleString();

export default function Security({ lockdown, integrity, backup, backupSets, failedLogins24h, lockouts24h }: Props) {
    const toggleLockdown = () => {
        const next = !lockdown;
        const msg = next
            ? 'Turn on lockdown? Writers are signed out immediately and only admins can sign in.'
            : 'Lift the lockdown and let writers sign in again?';
        if (confirm(msg)) router.post(route('admin.security.lockdown'), { enabled: next }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Security" />

            <div className="max-w-4xl space-y-6 p-4">
                <section
                    className={`space-y-3 rounded-xl border p-5 shadow-xs ${lockdown ? 'border-red-300 bg-red-50' : 'bg-card'}`}
                    aria-label="Lockdown"
                >
                    <h2 className="flex items-center gap-2 font-semibold">
                        <ShieldAlert className="size-4" /> Lockdown {lockdown && <span className="text-red-700">(ON)</span>}
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Use this if you suspect an account was taken over. Writers are signed out and cannot sign in until you lift it. Admins keep
                        access. Then reset passwords from Team and review the{' '}
                        <Link href={route('admin.audit-logs.index')} className="underline">
                            audit log
                        </Link>
                        .
                    </p>
                    <Button variant={lockdown ? 'outline' : 'destructive'} onClick={toggleLockdown}>
                        {lockdown ? 'Lift lockdown' : 'Turn on lockdown'}
                    </Button>
                </section>

                <section className="bg-card space-y-3 rounded-xl border p-5 shadow-xs" aria-label="Content integrity">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-semibold">Content integrity</h2>
                        <Button size="sm" variant="outline" onClick={() => router.post(route('admin.security.check'), {}, { preserveScroll: true })}>
                            Check now
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Every save records a fingerprint of the published text. If a page or post is changed directly in the database, it shows up
                        here and admins are emailed.
                    </p>
                    {!integrity && <p className="text-sm">Not checked yet.</p>}
                    {integrity && integrity.mismatches.length === 0 && (
                        <p className="flex items-center gap-2 text-sm text-emerald-700">
                            <CheckCircle2 className="size-4" /> All {integrity.checked} published items match. Last check {when(integrity.at)}.
                        </p>
                    )}
                    {integrity && integrity.mismatches.length > 0 && (
                        <div role="alert" className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3">
                            <p className="flex items-center gap-2 text-sm font-medium text-amber-900">
                                <AlertTriangle className="size-4" /> {integrity.mismatches.length} item(s) changed outside the CMS
                            </p>
                            <ul className="divide-y text-sm">
                                {integrity.mismatches.map((m) => (
                                    <li key={`${m.type}-${m.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span>
                                            <span className="text-muted-foreground uppercase">{m.type}</span> {m.title}
                                        </span>
                                        <span className="flex gap-2">
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={route(m.type === 'page' ? 'admin.pages.edit' : 'admin.posts.edit', m.id)}>
                                                    Review / restore
                                                </Link>
                                            </Button>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    confirm('Confirm this content is legitimate? It becomes the new baseline.') &&
                                                    router.post(route('admin.security.reseal'), { type: m.type, id: m.id }, { preserveScroll: true })
                                                }
                                            >
                                                It is fine
                                            </Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-amber-900">To undo a change, open the item and restore an earlier revision.</p>
                        </div>
                    )}
                </section>

                <section className="bg-card space-y-3 rounded-xl border p-5 shadow-xs" aria-label="Backups">
                    <h2 className="font-semibold">Backups</h2>
                    {!backup && <p className="text-sm">No backup has run yet. Backups run daily when the scheduler is active (see README).</p>}
                    {backup?.ok && (
                        <p className="flex items-center gap-2 text-sm text-emerald-700">
                            <CheckCircle2 className="size-4" /> Last backup {when(backup.at)} ({Math.round(backup.bytes / 1024)} KB).
                        </p>
                    )}
                    {backup && !backup.ok && (
                        <p role="alert" className="flex items-center gap-2 text-sm text-amber-900">
                            <AlertTriangle className="size-4" /> Last backup failed {when(backup.at)}: {backup.error}
                        </p>
                    )}
                    {backupSets.length > 0 && (
                        <ul className="text-muted-foreground font-mono text-xs">
                            {backupSets.map((s) => (
                                <li key={s}>{s}</li>
                            ))}
                        </ul>
                    )}
                    <p className="text-muted-foreground text-xs">
                        Restore with <code>php artisan cms:restore &lt;file&gt; --force</code> (see docs/DEPLOY.md).
                    </p>
                </section>

                <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Sign-in activity">
                    <h2 className="mb-2 font-semibold">Sign-in activity (24 h)</h2>
                    <p className="text-sm">
                        {failedLogins24h} failed attempts, {lockouts24h} lockouts.{' '}
                        <Link href={route('admin.audit-logs.index', { action: 'login' })} className="text-primary underline">
                            View in the audit log
                        </Link>
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
