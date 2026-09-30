import { usePage } from '@inertiajs/react';

/**
 * The organization resolved from the current route. Only use on pages
 * whose route is tied to an organization (slug or a record owned by one).
 *
 * @returns {App.Data.OrganizationData}
 */
export function useCurrentOrganization() {
    const { currentOrganization } = usePage().props;

    if (!currentOrganization) {
        throw new Error('This page requires a current organization.');
    }

    return currentOrganization;
}
