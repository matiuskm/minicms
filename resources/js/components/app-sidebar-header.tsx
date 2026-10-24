import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { auth } = usePage<SharedData>().props;
    const initials = useInitials();

    return (
        <header className="bg-card border-border flex h-16 shrink-0 items-center justify-between gap-2 border-b px-6 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
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
        </header>
    );
}
