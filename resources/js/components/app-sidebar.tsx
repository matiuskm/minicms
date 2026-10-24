import { NavMain } from '@/components/nav-main';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    File,
    FolderTree,
    Image,
    LayoutGrid,
    ListTree,
    Newspaper,
    Palette,
    RefreshCw,
    ScrollText,
    ShieldCheck,
    SlidersHorizontal,
    Tags,
    UserCog,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Posts', url: '/admin/posts', icon: Newspaper },
    { title: 'Media', url: '/media', icon: Image },
];

const adminNavItems: NavItem[] = [
    { title: 'Pages', url: '/admin/pages', icon: File },
    { title: 'Statistics', url: '/admin/analytics', icon: BarChart3 },
    { title: 'Categories', url: '/admin/categories', icon: FolderTree },
    { title: 'Tags', url: '/admin/tags', icon: Tags },
    { title: 'Menus', url: '/admin/menus', icon: ListTree },
    { title: 'Appearance', url: '/admin/appearance', icon: Palette },
    { title: 'Team', url: '/admin/users', icon: Users },
    { title: 'Audit log', url: '/admin/audit-logs', icon: ScrollText },
    { title: 'Security', url: '/admin/security', icon: ShieldCheck },
    { title: 'Updates', url: '/admin/updates', icon: RefreshCw },
    { title: 'Site settings', url: '/admin/settings', icon: SlidersHorizontal },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const { updateAvailable } = usePage<SharedData>().props;
    const items = (auth.user.role === 'admin' ? [...mainNavItems, ...adminNavItems] : mainNavItems).map((i) =>
        i.url === '/admin/updates' && updateAvailable ? { ...i, badge: updateAvailable } : i,
    );

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild className="hover:bg-sidebar-accent">
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild>
                            <Link href="/settings/profile" prefetch>
                                <UserCog />
                                <span>My account</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
        </Sidebar>
    );
}
