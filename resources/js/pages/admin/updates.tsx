import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Updates', href: '/admin/updates' }];

interface UpdateStatus {
    state: 'idle' | 'queued' | 'running' | 'success' | 'failed';
    step?: string;
    message?: string;
    version?: string;
    from?: string;
    log?: string[];
    started_at?: string | null;
    finished_at?: string | null;
}

interface Props {
    current: string;
    latest: { version: string; notes: string; published_at: string | null; url: string | null; signed: boolean } | null;
    available: boolean;
    enabled: boolean;
    status: UpdateStatus;
}

const STEPS: [string, string][] = [
    ['checking', 'Checking the release'],
    ['downloading', 'Downloading'],
    ['verifying', 'Verifying the signature'],
    ['backing_up', 'Taking a backup'],
    ['migrating', 'Updating the database'],
    ['switching', 'Switching to the new version'],
    ['health_check', 'Checking the site is healthy'],
];

export default function Updates({ current, latest, available, enabled, status: initial }: Props) {
    const { errors, flash } = usePage<SharedData & { errors: Record<string, string>; flash?: { status?: string } }>().props;
    const [status, setStatus] = useState<UpdateStatus>(initial);
    const active = status.state === 'queued' || status.state === 'running';

    // Poll while an update runs. The site briefly restarts, so failed polls are simply retried.
    useEffect(() => {
        if (!active) return;
        const timer = setInterval(async () => {
            try {
                const res = await fetch(route('admin.updates.status'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (res.ok) {
                    const next: UpdateStatus = await res.json();
                    setStatus(next);
                    if (next.state === 'success') setTimeout(() => router.reload(), 1500);
                }
            } catch {
                /* restarting */
            }
        }, 3000);
        return () => clearInterval(timer);
    }, [active]);

    const install = () => {
        if (!latest) return;
        if (confirm(`Update from ${current} to ${latest.version}? A backup is taken first and the site is briefly in maintenance mode.`)) {
            router.post(
                route('admin.updates.install'),
                { version: latest.version },
                { preserveScroll: true, onSuccess: () => setStatus({ ...status, state: 'queued', version: latest.version }) },
            );
        }
    };

    const stepIndex = STEPS.findIndex(([k]) => k === status.step);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Updates" />

            <div className="max-w-3xl space-y-6 p-4">
                {flash?.status && <p className="text-sm text-green-600">{flash.status}</p>}
                {errors?.version && (
                    <p role="alert" className="text-sm text-red-600">
                        {errors.version}
                    </p>
                )}

                <section className="bg-card flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5 shadow-xs" aria-label="Version">
                    <div>
                        <p className="text-muted-foreground text-xs">Installed version</p>
                        <p className="text-2xl font-semibold">{current}</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {!latest && <span className="text-muted-foreground text-sm">Could not reach the release server.</span>}
                        {latest && !available && (
                            <span className="flex items-center gap-1.5 text-sm text-emerald-700">
                                <CheckCircle2 className="size-4" /> You are up to date
                            </span>
                        )}
                        <Button variant="outline" size="sm" onClick={() => router.post(route('admin.updates.check'), {}, { preserveScroll: true })}>
                            <RefreshCw className="size-4" /> Check for updates
                        </Button>
                    </div>
                </section>

                {available && latest && (
                    <section className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-5" aria-label="Update available">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold text-amber-950">Version {latest.version} is available</h2>
                                {latest.published_at && (
                                    <p className="text-xs text-amber-900">Released {new Date(latest.published_at).toLocaleDateString()}</p>
                                )}
                            </div>
                            {enabled && latest.signed && !active && <Button onClick={install}>Update to {latest.version}</Button>}
                        </div>
                        {!latest.signed && (
                            <p className="text-sm text-red-700">This release has no signed package, so it cannot be installed from here.</p>
                        )}
                        {latest.notes && (
                            <div>
                                <p className="mb-1 text-xs font-medium text-amber-900">What changed</p>
                                {/* Release notes come from the network: shown as plain text only. */}
                                <pre className="max-h-64 overflow-auto rounded-md bg-white/70 p-3 text-xs whitespace-pre-wrap">{latest.notes}</pre>
                            </div>
                        )}
                        {latest.url && (
                            <a href={latest.url} target="_blank" rel="noopener noreferrer" className="text-xs underline">
                                Full release notes
                            </a>
                        )}
                    </section>
                )}

                {available && !enabled && (
                    <section className="bg-card space-y-2 rounded-xl border p-5 text-sm shadow-xs" aria-label="Manual update">
                        <h2 className="font-semibold">One-click updates are off on this server</h2>
                        <p className="text-muted-foreground">
                            The web application deliberately cannot change its own code. To enable the button, install the updater described in{' '}
                            <code>docs/DEPLOY.md</code> (“One-click updates”) and set <code>CMS_UPDATES=true</code>. Until then, deploy the new
                            release as usual.
                        </p>
                    </section>
                )}

                {status.state !== 'idle' && (
                    <section className="bg-card space-y-3 rounded-xl border p-5 shadow-xs" aria-label="Update progress">
                        <h2 className="flex items-center gap-2 font-semibold">
                            {active && <Loader2 className="size-4 animate-spin" />}
                            {status.state === 'success' && <CheckCircle2 className="size-4 text-emerald-600" />}
                            {status.state === 'failed' && <AlertTriangle className="size-4 text-red-600" />}
                            {status.state === 'queued' && 'Waiting to start…'}
                            {status.state === 'running' && `Updating to ${status.version}…`}
                            {status.state === 'success' && `Updated to ${status.version}`}
                            {status.state === 'failed' && `Update to ${status.version} failed`}
                        </h2>
                        {status.message && <p className="text-sm">{status.message}</p>}
                        {(status.state === 'running' || status.state === 'queued') && (
                            <ol className="space-y-1 text-sm">
                                {STEPS.map(([k, label], i) => (
                                    <li
                                        key={k}
                                        className={i < stepIndex ? 'text-emerald-700' : i === stepIndex ? 'font-medium' : 'text-muted-foreground'}
                                    >
                                        {i < stepIndex ? '✓' : i === stepIndex ? '▸' : '○'} {label}
                                    </li>
                                ))}
                            </ol>
                        )}
                        {status.state === 'failed' && (
                            <p className="text-sm text-red-700">
                                The site was left on version {status.from ?? current}. Nothing was lost; a backup was taken before the attempt.
                            </p>
                        )}
                        {status.log && status.log.length > 0 && (
                            <details>
                                <summary className="text-muted-foreground cursor-pointer text-xs">Details</summary>
                                <pre className="mt-2 max-h-56 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">
                                    {status.log.join('\n')}
                                </pre>
                            </details>
                        )}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
