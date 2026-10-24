import { Head, router } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sessions', href: '/settings/sessions' }];

interface Session {
    id: string;
    ip: string | null;
    agent: string;
    last_active: string;
    current: boolean;
}

export default function Sessions({ sessions }: { sessions: Session[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sessions" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Active sessions" description="Devices currently signed in to your account" />

                    <ul className="divide-y rounded-md border">
                        {sessions.map((s) => (
                            <li key={s.id} className="flex items-center justify-between gap-4 p-3 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate">{s.agent || 'Unknown device'}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {s.ip} · {new Date(s.last_active).toLocaleString()}
                                        {s.current && ' · this device'}
                                    </p>
                                </div>
                                {!s.current && (
                                    <Button size="sm" variant="outline" onClick={() => router.delete(route('sessions.destroy', s.id), { preserveScroll: true })}>
                                        Revoke
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>

                    {sessions.length > 1 && (
                        <Button variant="destructive" onClick={() => router.delete(route('sessions.destroy-others'), { preserveScroll: true })}>
                            Log out other devices
                        </Button>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
