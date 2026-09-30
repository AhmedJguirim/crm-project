import { Head } from '@inertiajs/react';

export default function Welcome({ appName }) {
    return (
        <>
            <Head title="Welcome" />

            <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
                <div className="text-center">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-900">Welcome</h1>
                    <p className="mt-3 text-slate-600">
                        {appName} — powered by Inertia &amp; React.
                    </p>
                </div>
            </main>
        </>
    );
}
