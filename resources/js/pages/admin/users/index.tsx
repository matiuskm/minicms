import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

interface Row {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'writer';
    status: 'invited' | 'active' | 'disabled';
    two_factor: boolean;
    locked: boolean;
    last_login_at: string | null;
}

interface Props {
    users: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q: string };
}

export default function Users({ users, filters }: Props) {
    const { auth, flash } = usePage<SharedData & { flash?: { status?: string } }>().props;
    const [q, setQ] = useState(filters.q);
    const invite = useForm({ name: '', email: '', role: 'writer' });
    const errors = usePage().props.errors as Record<string, string>;

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), { q }, { preserveState: true });
    };

    const submitInvite: FormEventHandler = (e) => {
        e.preventDefault();
        invite.post(route('admin.users.store'), { preserveScroll: true, onSuccess: () => invite.reset() });
    };

    const act = (name: string, user: Row, confirmText?: string) => {
        if (confirmText && !confirm(confirmText)) return;
        router.post(route(name, user.id), {}, { preserveScroll: true });
    };

    const changeRole = (user: Row, role: string) =>
        router.put(route('admin.users.update', user.id), { name: user.name, role }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                {flash?.status && <p className="text-sm text-green-600">{flash.status}</p>}
                {errors?.user && <p className="text-sm text-red-600">{errors.user}</p>}

                <form onSubmit={submitInvite} className="grid items-end gap-3 rounded-md border p-4 md:grid-cols-5">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={invite.data.name} onChange={(e) => invite.setData('name', e.target.value)} />
                        <InputError message={invite.errors.name} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)} />
                        <InputError message={invite.errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <select
                            id="role"
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={invite.data.role}
                            onChange={(e) => invite.setData('role', e.target.value)}
                        >
                            <option value="writer">Writer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <Button disabled={invite.processing}>Invite</Button>
                </form>

                <form onSubmit={search} className="flex gap-2">
                    <Input placeholder="Search name or email" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" />
                    <Button variant="outline">Search</Button>
                </form>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">User</th>
                                <th className="p-3">Role</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">2FA</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((u) => {
                                const self = u.id === auth.user.id;
                                return (
                                    <tr key={u.id} className="border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{u.name}</div>
                                            <div className="text-muted-foreground text-xs">{u.email}</div>
                                        </td>
                                        <td className="p-3">
                                            <select
                                                aria-label={`Role for ${u.name}`}
                                                disabled={self}
                                                value={u.role}
                                                onChange={(e) => changeRole(u, e.target.value)}
                                                className="border-input h-8 rounded-md border bg-transparent px-2"
                                            >
                                                <option value="writer">Writer</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={u.status === 'active' ? 'default' : 'secondary'}>{u.locked ? 'locked' : u.status}</Badge>
                                        </td>
                                        <td className="p-3">{u.two_factor ? 'on' : 'off'}</td>
                                        <td className="space-x-2 p-3 text-right">
                                            {u.status === 'invited' && (
                                                <Button size="sm" variant="outline" onClick={() => act('admin.users.resend-invite', u)}>
                                                    Resend invite
                                                </Button>
                                            )}
                                            {u.status !== 'invited' && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => act('admin.users.force-reset', u, `Force a password reset for ${u.name}?`)}
                                                >
                                                    Force reset
                                                </Button>
                                            )}
                                            {u.status === 'disabled' ? (
                                                <Button size="sm" variant="outline" onClick={() => act('admin.users.enable', u)}>
                                                    Enable
                                                </Button>
                                            ) : (
                                                !self && (
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() =>
                                                            act('admin.users.disable', u, `Disable ${u.name}? Their sessions end immediately.`)
                                                        }
                                                    >
                                                        Disable
                                                    </Button>
                                                )
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <nav className="flex flex-wrap gap-1">
                    {users.links.map((l, i) => (
                        <Button
                            key={i}
                            size="sm"
                            variant={l.active ? 'default' : 'outline'}
                            disabled={!l.url}
                            onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}
                        >
                            {l.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                        </Button>
                    ))}
                </nav>
            </div>
        </AppLayout>
    );
}
