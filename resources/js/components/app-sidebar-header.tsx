import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { auth } = usePage<SharedData>().props;
    const initials = useInitials();

    return (
        <header className="bg-card border-border flex h-16 shrink-0 items-center justify-between gap-2 border-b px-6 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-4">
                {/* Opens the public site in a new tab so changes can be checked straight away (like WordPress). */}
                <a
                    href="/"
                    target="_blank"
                    rel="noopener"
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm font-medium"
                >
                    Visit Website <ExternalLink className="size-3.5" />
                </a>
                <DropdownMenu>
                    <DropdownMenuTrigger aria-label="Account menu" className="rounded-full focus-visible:ring-2">
                        <Avatar className="size-9">
                            <AvatarFallback className="bg-primary text-primary-foreground text-sm">{initials(auth.user.name)}</AvatarFallback>
                        </Avatar>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56 rounded-lg">
                        <UserMenuContent user={auth.user as never} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
