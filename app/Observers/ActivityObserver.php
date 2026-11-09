<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ActivityObserver
{
    /**
     * Fills a missing organization from the contact, then the deal, then the company (first one set wins).
     */
    public function creating(Activity $activity): void
    {
        if (! $activity->organization_id) {
            $activity->organization_id = $this->organizationIdFromLinks($activity);
        }
    }

    /**
     * Refuses a contact, deal or company of another organization.
     *
     * @throws ModelNotFoundException
     */
    public function saving(Activity $activity): void
    {
        $this->ensureLinksOfOrganization($activity);
    }

    public function saved(Activity $activity): void
    {
        ResyncContactSegments::dispatchForContacts($activity->organization_id, [$activity->contact_id, $activity->getOriginal('contact_id')]);
    }

    public function deleted(Activity $activity): void
    {
        ResyncContactSegments::dispatchForContacts($activity->organization_id, [$activity->contact_id]);
    }

    private function organizationIdFromLinks(Activity $activity): ?int
    {
        if ($activity->contact_id) {
            return Contact::withoutGlobalScopes()->whereKey($activity->contact_id)->value('organization_id');
        }

        if ($activity->deal_id) {
            return Deal::withoutGlobalScopes()->whereKey($activity->deal_id)->value('organization_id');
        }

        if ($activity->company_id) {
            return Company::withoutGlobalScopes()->whereKey($activity->company_id)->value('organization_id');
        }

        return null;
    }

    /**
     * Each changed link must exist in the activity's organization (a soft-deleted contact or company still counts, its
     * activities stay editable). Skipped without an organization: the NOT NULL constraint then refuses the row.
     *
     * @throws ModelNotFoundException
     */
    private function ensureLinksOfOrganization(Activity $activity): void
    {
        $organizationId = $activity->organization_id
            ?: app(TenantContext::class)->id()
            ?: $this->organizationIdFromLinks($activity);

        if (! $organizationId) {
            return;
        }

        if ($activity->contact_id && $activity->isDirty('contact_id')) {
            Contact::withTrashed()->forOrganization($organizationId)->findOrFail($activity->contact_id);
        }

        if ($activity->deal_id && $activity->isDirty('deal_id')) {
            Deal::forOrganization($organizationId)->findOrFail($activity->deal_id);
        }

        if ($activity->company_id && $activity->isDirty('company_id')) {
            Company::withTrashed()->forOrganization($organizationId)->findOrFail($activity->company_id);
        }
    }
}
