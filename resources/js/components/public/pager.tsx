import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';

export interface PagerData {
    current: number;
    last: number;
    prev: string | null;
    next: string | null;
    pages: { n: number; url: string }[];
}

export function Pager({ pager }: { pager: PagerData | null }) {
    if (!pager || pager.last <= 1) return null;

    return (
        <nav className="site-pager" aria-label="Pagination">
            {pager.prev ? (
                <Link href={pager.prev} className="arrow" aria-label="Previous page" preserveScroll={false}>
                    <ArrowLeft className="size-3.5" />
                </Link>
            ) : (
                <span className="btn" aria-hidden />
            )}
            <ol>
                {pager.pages.map((p) => (
                    <li key={p.n}>
                        <Link href={p.url} aria-current={p.n === pager.current ? 'page' : undefined} aria-label={`Page ${p.n}`}>
                            {p.n}
                        </Link>
                    </li>
                ))}
            </ol>
            {pager.next ? (
                <Link href={pager.next} className="arrow" aria-label="Next page" preserveScroll={false}>
                    <ArrowRight className="size-3.5" />
                </Link>
            ) : (
                <span className="btn" aria-hidden />
            )}
        </nav>
    );
}
