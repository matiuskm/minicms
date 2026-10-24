export interface Day {
    day: string;
    views: number;
    visitors: number;
}

/** Bars for page views with a dot for unique visitors. Plain SVG; each day has a text alternative for screen readers. */
export function DailyChart({ data }: { data: Day[] }) {
    const W = 720;
    const H = 180;
    const pad = { l: 36, r: 8, t: 10, b: 22 };
    const max = Math.max(1, ...data.map((d) => d.views));
    const step = (W - pad.l - pad.r) / data.length;
    const y = (v: number) => pad.t + (H - pad.t - pad.b) * (1 - v / max);
    const label = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const ticks = [0, Math.ceil(max / 2), max];

    return (
        <figure>
            <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Daily page views and unique visitors" className="w-full">
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="currentColor" strokeOpacity={0.12} />
                        <text x={pad.l - 6} y={y(t) + 3} textAnchor="end" fontSize={10} fill="currentColor" fillOpacity={0.6}>
                            {t}
                        </text>
                    </g>
                ))}
                {data.map((d, i) => {
                    const x = pad.l + i * step;
                    const barW = Math.max(2, step * 0.7);
                    return (
                        <g key={d.day}>
                            <rect
                                x={x + (step - barW) / 2}
                                y={y(d.views)}
                                width={barW}
                                height={Math.max(0, H - pad.b - y(d.views))}
                                rx={2}
                                fill="var(--primary)"
                                fillOpacity={0.85}
                            >
                                <title>{`${label(d.day)}: ${d.views} views, ${d.visitors} visitors`}</title>
                            </rect>
                            {d.visitors > 0 && <circle cx={x + step / 2} cy={y(d.visitors)} r={2.5} fill="#0f172a" />}
                            {(i === 0 || i === data.length - 1 || i % Math.ceil(data.length / 6) === 0) && (
                                <text x={x + step / 2} y={H - 6} textAnchor="middle" fontSize={10} fill="currentColor" fillOpacity={0.6}>
                                    {label(d.day)}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <figcaption className="text-muted-foreground mt-1 flex gap-4 text-xs">
                <span>
                    <span className="bg-primary mr-1 inline-block size-2 rounded-sm align-middle" />
                    Page views
                </span>
                <span>
                    <span className="mr-1 inline-block size-2 rounded-full bg-slate-900 align-middle" />
                    Unique visitors
                </span>
            </figcaption>
            <table className="sr-only">
                <caption>Daily page views and unique visitors</caption>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Views</th>
                        <th>Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((d) => (
                        <tr key={d.day}>
                            <td>{d.day}</td>
                            <td>{d.views}</td>
                            <td>{d.visitors}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
