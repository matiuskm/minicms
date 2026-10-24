import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.challenge'), { onFinish: () => reset() });
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={useRecovery ? 'Enter one of your recovery codes' : 'Enter the 6-digit code from your authenticator app'}
        >
            <Head title="Two-factor authentication" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="code">{useRecovery ? 'Recovery code' : 'Authentication code'}</Label>
                    {useRecovery ? (
                        <Input
                            id="code"
                            autoFocus
                            autoComplete="off"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                    ) : (
                        <Input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                        />
                    )}
                    <InputError message={errors.code} />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Continue
                </Button>

                <button
                    type="button"
                    className="text-muted-foreground text-sm underline"
                    onClick={() => {
                        setUseRecovery(!useRecovery);
                        reset();
                    }}
                >
                    {useRecovery ? 'Use authentication code' : 'Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
