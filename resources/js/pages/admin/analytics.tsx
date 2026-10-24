import { Head, Link, router } from '@inertiajs/react';
import { Check, Copy, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { DailyChart, type Day } from '@/components/analytics/daily-chart';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { buildLink, CHANNELS, sourceLabel } from '@/lib/analytics';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Statistics', href: '/admin/analytics' }];

interface Props {
    totals: { views: number; visitors: number; topSource: string | null };
    daily: Day[];
    sources: { source: string; views: number; visitors: number }[];
    pages: { key: string; label: string; views: number; visitors: number }[];
    campaigns: { source: string; campaign: string; views: number }[];
    referrers: { host: string; views: number }[];
    range: number;
    ranges: number[];
    filter: { key: string; label: string } | null;
    enabled: boolean;
    retention: number;
    builder: { site: string; targets: { label: string; path: string }[] };
}

const n = (v: number) => v.toLocaleString();
const selectClass = 'border-input bg-card h-9 w-full rounded-md border px-2 text-sm';

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="bg-card rounded-xl border p-4 shadow-xs">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="mt-1 text-3xl font-semibold">{value}</p>
            {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
        </div>
    );
}

function LinkBuilder({ site, targets }: Props['builder']) {
    const [path, setPath] = useState(targets[0]?.path ?? '/');
    const [channel, setChannel] = useState('instagram');
    const [campaign, setCampaign] = useState('');
    const [copied, setCopied] = useState(false);
    const link = useMemo(() => buildLink(site, path, channel, campaign), [site, path, channel, campaign]);

    const copy = async () => {
        await navigator.clipboard?.writeText(link);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <section className="bg-card space-y-3 rounded-xl border p-5 shadow-xs" aria-label="Campaign link builder">
            <div>
                <h2 className="font-semibold">Tracked link builder</h2>
                <p className="text-muted-foreground text-xs">
                    Apps like WhatsApp and Instagram usually hide where a click came from. Share the tagged link below and the visit is counted under
                    the channel you pick.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="lb-target">Page to share</Label>
                    <select id="lb-target" className={selectClass} value={path} onChange={(e) => setPath(e.target.value)}>
                        {targets.map((t) => (
                            <option key={t.path} value={t.path}>
                                {t.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="lb-channel">Where will you post it?</Label>
                    <select id="lb-channel" className={selectClass} value={channel} onChange={(e) => setChannel(e.target.value)}>
                        {CHANNELS.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="lb-campaign">Campaign name (optional)</Label>
                    <Input
                        id="lb-campaign"
                        maxLength={60}
                        placeholder="e.g. launch-week"
                        value={campaign}
                        onChange={(e) => setCampaign(e.target.value)}
                    />
                </div>
            </div>
            <div className="flex gap-2">
                <Input readOnly aria-label="Tracked link" value={link} className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </section>
    );
}

function Bars({ rows, total, empty }: { rows: { label: string; views: number; sub?: string; dim?: boolean }[]; total: number; empty: string }) {
    if (rows.length === 0) return <p className="text-muted-foreground text-sm">{empty}</p>;
    return (
        <ul className="space-y-2">
            {rows.map((r) => (
                <li key={r.label + (r.sub ?? '')} className={cn('text-sm', r.dim && 'opacity-60')}>
                    <div className="flex justify-between gap-2">
                        <span className="truncate">
                            {r.label}
                            {r.sub && <span className="text-muted-foreground"> · {r.sub}</span>}
                        </span>
                        <span className="tabular-nums">
                            {n(r.views)} <span className="text-muted-foreground text-xs">({total ? Math.round((r.views / total) * 100) : 0}%)</span>
                        </span>
                    </div>
                    <div className="bg-muted mt-1 h-1.5 overflow-hidden rounded-full" aria-hidden>
                        <div className="bg-primary h-full rounded-full" style={{ width: `${total ? (r.views / total) * 100 : 0}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default function Analytics(props: Props) {
    const { totals, daily, sources, pages, campaigns, referrers, range, ranges, filter, enabled, retention, builder } = props;
    const go = (next: Record<string, string | number | null>) =>
        router.get(route('admin.analytics.index'), { range, page_key: filter?.key ?? null, ...next }, { preserveState: true, preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Statistics" />

            <div className="max-w-5xl space-y-6 p-4">
                {!enabled && (
                    <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        Visit counting is switched off.{' '}
                        <Link href={route('admin.settings.edit')} className="underline">
                            Turn it on in Site settings
                        </Link>
                        .
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{filter ? filter.label : 'Whole site'}</h1>
                        {filter && (
                            <button
                                className="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                                onClick={() => go({ page_key: null })}
                            >
                                <X className="size-3" /> Back to the whole site
                            </button>
                        )}
                    </div>
                    <div className="flex gap-1" role="group" aria-label="Period">
                        {ranges.map((r) => (
                            <Button
                                key={r}
                                size="sm"
                                variant={r === range ? 'default' : 'outline'}
                                aria-pressed={r === range}
                                onClick={() => go({ range: r })}
                            >
                                {r} days
                            </Button>
                        ))}
                    </div>
                </div>

                <section aria-label="Totals" className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Page views" value={n(totals.views)} />
                    <Stat label="Unique visitors" value={n(totals.visitors)} hint="Each person counts once per day" />
                    <Stat
                        label="Top outside source"
                        value={totals.topSource ? sourceLabel(totals.topSource) : '—'}
                        hint="Excludes direct and in-site clicks"
                    />
                </section>

                <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Daily trend">
                    <h2 className="mb-2 font-semibold">Daily trend</h2>
                    <DailyChart data={daily} />
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Traffic sources">
                        <h2 className="mb-3 font-semibold">Where visitors come from</h2>
                        <Bars
                            total={totals.views}
                            empty="No visits in this period yet."
                            rows={sources.map((s) => ({ label: sourceLabel(s.source), views: s.views, dim: s.source === 'internal' }))}
                        />
                    </section>

                    {!filter ? (
                        <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Top pages">
                            <h2 className="mb-3 font-semibold">Most viewed</h2>
                            {pages.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No visits in this period yet.</p>
                            ) : (
                                <ol className="space-y-2">
                                    {pages.map((p) => (
                                        <li key={p.key} className="flex justify-between gap-3 text-sm">
                                            <button
                                                className="hover:text-primary truncate text-left hover:underline"
                                                onClick={() => go({ page_key: p.key })}
                                                title="See where this page's visitors came from"
                                            >
                                                {p.label}
                                            </button>
                                            <span className="tabular-nums">{n(p.views)}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    ) : (
                        <section className="bg-card rounded-xl border p-5 shadow-xs">
                            <h2 className="mb-3 font-semibold">Visitors of this page</h2>
                            <p className="text-sm">
                                {n(totals.visitors)} unique visitors, {n(totals.views)} views in {range} days.
                            </p>
                        </section>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Campaigns">
                        <h2 className="mb-3 font-semibold">Campaigns</h2>
                        <Bars
                            total={totals.views}
                            empty="No tagged links used yet. Build one below."
                            rows={campaigns.map((c) => ({ label: c.campaign, sub: sourceLabel(c.source), views: c.views }))}
                        />
                    </section>
                    <section className="bg-card rounded-xl border p-5 shadow-xs" aria-label="Referring websites">
                        <h2 className="mb-3 font-semibold">Other websites sending visitors</h2>
                        <Bars total={totals.views} empty="No other websites yet." rows={referrers.map((r) => ({ label: r.host, views: r.views }))} />
                    </section>
                </div>

                <LinkBuilder {...builder} />

                <p className="text-muted-foreground text-xs">
                    Privacy: no cookies, no IP addresses or browser details are stored. Visitors are recognised only within a single day, staff
                    visits, bots and “Do Not Track” browsers are ignored, and records are deleted after {retention} days.
                </p>
            </div>
        </AppLayout>
    );
}
