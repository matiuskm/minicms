import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: string;
}

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    auth: Auth;
    twoFactorBypassed?: boolean;
    updateAvailable?: string | null;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    display_name: string | null;
    role: 'admin' | 'writer';
    avatar?: string;
    [key: string]: unknown; // This allows for additional properties...
}
