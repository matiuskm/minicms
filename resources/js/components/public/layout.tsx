import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { type ReactNode } from 'react';

import { SeoHead, type Seo } from '@/components/public/seo';
import { type SharedData } from '@/types';

export interface MenuLink {
    label: string;
    href: string;
    external: boolean;
}

export interface Site {
    name: string;
    tagline: string;
    language: string;
    logo: { src: string; alt: string | null } | null;
    favicon: string | null;
    cta: { label: string; url: string } | null;
    menu: { header: MenuLink[]; footer: MenuLink[] };
}

const FALLBACK: Site = { name: 'Site', tagline: '', language: 'en', logo: null, favicon: null, cta: null, menu: { header: [], footer: [] } };

function NavLink({ item }: { item: MenuLink }) {
    return item.external ? (
        <a href={item.href} rel="noopener noreferrer nofollow">
            {item.label}
        </a>
    ) : (
        <Link href={item.href}>{item.label}</Link>
    );
}

/** Header, footer and the rounded white shell from the public design reference. */
export function PublicLayout({ site = FALLBACK, seo, children }: { site?: Site | null; seo?: Seo; children: ReactNode }) {
    const s = site ?? FALLBACK;
    const { auth } = usePage<SharedData>().props;

    return (
        <div className="site">
            {seo && <SeoHead seo={seo} siteName={s.name} />}
            <div className="site-shell">
                <header className="site-header">
                    <Link href="/" className="site-logo" aria-label={`${s.name} home`}>
                        {s.logo ? <img src={s.logo.src} alt={s.logo.alt ?? s.name} /> : s.name}
                    </Link>
                    <nav className="site-nav" aria-label="Main">
                        {s.menu.header.map((m) => (
                            <NavLink key={m.href + m.label} item={m} />
                        ))}
                    </nav>
                    <div className="site-actions">
                        {auth?.user ? (
                            <Link href="/dashboard" className="inline-flex items-center gap-1">
                                Dashboard <ArrowUpRight className="size-3.5" />
                            </Link>
                        ) : (
                            <Link href="/login" className="inline-flex items-center gap-1">
                                Login <ArrowUpRight className="size-3.5" />
                            </Link>
                        )}
                        {s.cta && (
                            <a href={s.cta.url} className="site-cta">
                                {s.cta.label}
                            </a>
                        )}
                    </div>
                </header>

                <main>{children}</main>

                <footer className="site-footer">
                    <span>
                        © {new Date().getFullYear()} {s.name}
                    </span>
                    <nav aria-label="Footer">
                        {s.menu.footer.map((m) => (
                            <NavLink key={m.href + m.label} item={m} />
                        ))}
                        <a href="/rss.xml">RSS</a>
                    </nav>
                </footer>
            </div>
        </div>
    );
}
