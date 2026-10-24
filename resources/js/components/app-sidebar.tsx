import { NavMain } from '@/components/nav-main';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { File, FolderTree, Image, LayoutGrid, Newspaper, ScrollText, Settings, Tags, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Posts', url: '/admin/posts', icon: Newspaper },
    { title: 'Media', url: '/media', icon: Image },
];

const adminNavItems: NavItem[] = [
    { title: 'Pages', url: '/admin/pages', icon: File },
    { title: 'Categories', url: '/admin/categories', icon: FolderTree },
    { title: 'Tags', url: '/admin/tags', icon: Tags },
    { title: 'Team', url: '/admin/users', icon: Users },
    { title: 'Audit log', url: '/admin/audit-logs', icon: ScrollText },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const items = auth.user.role === 'admin' ? [...mainNavItems, ...adminNavItems] : mainNavItems;

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
                                <Settings />
                                <span>Settings</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
        </Sidebar>
    );
}
