import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-factor authentication', href: '/settings/two-factor' }];

interface Props {
    enabled: boolean;
    required: boolean;
    qr: string | null;
    secret: string | null;
    recoveryCodes: string[] | null;
    recoveryLeft: number;
}

export default function TwoFactor({ enabled, required, qr, secret, recoveryCodes, recoveryLeft }: Props) {
    const confirmForm = useForm({ code: '' });
    const passwordForm = useForm({ current_password: '' });

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        confirmForm.post(route('two-factor.confirm'), { preserveScroll: true, onSuccess: () => confirmForm.reset() });
    };

    const regenerate: FormEventHandler = (e) => {
        e.preventDefault();
        passwordForm.post(route('two-factor.recovery'), { preserveScroll: true, onFinish: () => passwordForm.reset() });
    };

    const disable: FormEventHandler = (e) => {
        e.preventDefault();
        passwordForm.delete(route('two-factor.destroy'), { preserveScroll: true, onFinish: () => passwordForm.reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor authentication" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Two-factor authentication"
                        description={required ? 'Mandatory for admins. Set it up to continue.' : 'Add a second step to your login'}
                    />

                    {recoveryCodes && (
                        <div className="rounded-md border p-4">
                            <p className="mb-2 text-sm font-medium">Save these recovery codes now. They are shown only once.</p>
                            <ul className="grid grid-cols-2 gap-1 font-mono text-sm">
                                {recoveryCodes.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {!enabled && qr && (
                        <form onSubmit={confirm} className="space-y-4">
                            <p className="text-sm">Scan this QR code with your authenticator app, then enter the 6-digit code.</p>
                            <img src={qr} alt="Two-factor QR code" width={192} height={192} className="rounded-md border bg-white" />
                            <p className="text-muted-foreground text-xs">
                                Or enter this key manually: <span className="font-mono">{secret}</span>
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    maxLength={6}
                                    autoComplete="one-time-code"
                                    value={confirmForm.data.code}
                                    onChange={(e) => confirmForm.setData('code', e.target.value)}
                                />
                                <InputError message={confirmForm.errors.code} />
                            </div>
                            <Button disabled={confirmForm.processing}>Enable</Button>
                        </form>
                    )}

                    {enabled && (
                        <div className="space-y-4">
                            <p className="text-sm">
                                Two-factor authentication is <strong>enabled</strong>. Recovery codes left: {recoveryLeft}.
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">Current password</Label>
                                <Input
                                    id="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={passwordForm.data.current_password}
                                    onChange={(e) => passwordForm.setData('current_password', e.target.value)}
                                />
                                <InputError message={passwordForm.errors.current_password} />
                            </div>
                            <div className="flex gap-3">
                                <form onSubmit={regenerate}>
                                    <Button variant="outline" disabled={passwordForm.processing}>
                                        Regenerate recovery codes
                                    </Button>
                                </form>
                                {!required && (
                                    <form onSubmit={disable}>
                                        <Button variant="destructive" disabled={passwordForm.processing}>
                                            Disable
                                        </Button>
                                    </form>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
