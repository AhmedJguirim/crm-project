import { Head, Link } from '@inertiajs/react';
import { useCurrentOrganization } from '../../hooks/useCurrentOrganization';

/**
 * @param {{ tag: App.Data.TagData }} props
 */
export default function TagsShow({ tag }) {
    const currentOrganization = useCurrentOrganization();

    return (
        <>
            <Head title={`${tag.name} · ${currentOrganization.name}`} />

            <main className="mx-auto max-w-3xl px-4 py-10">
                <Link
                    href={`/app/${currentOrganization.slug}/tags`}
                    className="text-sm text-slate-500 hover:text-slate-800"
                >
                    ← {currentOrganization.name} tags
                </Link>

                <div className="mt-6 flex items-center gap-3">
                    <span
                        className="size-4 rounded-full"
                        style={{ backgroundColor: tag.color ?? '#94a3b8' }}
                    />
                    <h1 className="text-2xl font-semibold text-slate-900">{tag.name}</h1>
                </div>

                <dl className="mt-6 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt className="text-slate-500">Color</dt>
                    <dd className="text-slate-800">{tag.color ?? '—'}</dd>

                    <dt className="text-slate-500">Created</dt>
                    <dd className="text-slate-800">{new Date(tag.created_at).toLocaleDateString()}</dd>
                </dl>
            </main>
        </>
    );
}
