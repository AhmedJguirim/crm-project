import { Head, Link, usePage } from '@inertiajs/react';
import { useCurrentOrganization } from '../../hooks/useCurrentOrganization';

/**
 * @param {{ tags: App.Data.TagData[] }} props
 */
export default function TagsIndex({ tags }) {
    const currentOrganization = useCurrentOrganization();
    const { organizations } = usePage().props;

    return (
        <>
            <Head title={`Tags · ${currentOrganization.name}`} />

            <main className="mx-auto max-w-3xl px-4 py-10">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-sm text-slate-500">{currentOrganization.name}</p>
                        <h1 className="text-2xl font-semibold text-slate-900">Tags</h1>
                    </div>

                    <nav className="flex flex-wrap gap-2">
                        {organizations.map((organization) => (
                            <Link
                                key={organization.id}
                                href={`/app/${organization.slug}/tags`}
                                className={
                                    organization.id === currentOrganization.id
                                        ? 'rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white'
                                        : 'rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100'
                                }
                            >
                                {organization.name}
                            </Link>
                        ))}
                    </nav>
                </header>

                {tags.length === 0 ? (
                    <p className="mt-8 rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-500">
                        No tags in this organization yet.
                    </p>
                ) : (
                    <ul className="mt-8 flex flex-wrap gap-2">
                        {tags.map((tag) => (
                            <li key={tag.id}>
                                <Link
                                    href={`/app/tags/${tag.id}`}
                                    className="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-800 hover:bg-slate-100"
                                >
                                    <span
                                        className="size-2.5 rounded-full"
                                        style={{ backgroundColor: tag.color ?? '#94a3b8' }}
                                    />
                                    {tag.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </>
    );
}
