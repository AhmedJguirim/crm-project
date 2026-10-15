<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DealObserver
{
    /**
     * Fills the organization from the tenant context first (the trait's own listener may run after this one), then
     * refuses a pipeline stage of another organization.
     */
    public function creating(Deal $deal): void
    {
        if (! $deal->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $deal->organization_id = $organizationId;
        }

        $this->ensureStageOfOrganization($deal);
    }

    public function updating(Deal $deal): void
    {
        if ($deal->isDirty('pipeline_stage_id')) {
            $this->ensureStageOfOrganization($deal);
        }
    }

    public function saved(Deal $deal): void
    {
        ResyncContactSegments::dispatchForContacts($deal->organization_id, [$deal->contact_id, $deal->getOriginal('contact_id')]);
    }

    public function deleted(Deal $deal): void
    {
        ResyncContactSegments::dispatchForContacts($deal->organization_id, [$deal->contact_id]);
    }

    /**
     * A deal can only be in a stage of its own organization's pipeline: another organization's stage id is not found.
     * Skipped without an organization or a stage id (the NOT NULL constraints then refuse the row).
     *
     * @throws ModelNotFoundException
     */
    private function ensureStageOfOrganization(Deal $deal): void
    {
        if (! $deal->organization_id || ! $deal->pipeline_stage_id) {
            return;
        }

        PipelineStage::forOrganization($deal->organization_id)->findOrFail($deal->pipeline_stage_id);
    }
}
