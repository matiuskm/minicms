import { Head, Link, usePage } from '@inertiajs/react';

import { type SharedData } from '@/types';

// Placeholder until the public site lands in P-04.
export default function Welcome() {
    const { auth, name } = usePage<SharedData>().props;

    return (
        <>
            <Head title={name} />
            <div className="flex min-h-screen flex-col items-center justify-center gap-4">
                <h1 className="text-2xl font-medium">{name}</h1>
                {auth.user && (
                    <Link href={route('dashboard')} className="text-sm underline">
                        Dashboard
                    </Link>
                )}
            </div>
        </>
    );
}
