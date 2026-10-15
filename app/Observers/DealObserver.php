<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Deals\DefaultPipeline;
use App\Support\Tenancy\TenantContext;

class DealObserver
{
    /**
     * Fills the organization from the tenant context first (the trait's own listener may run after this one), then
     * mirrors the pipeline stage into the legacy `stage` column, overwriting any given one.
     */
    public function creating(Deal $deal): void
    {
        if (! $deal->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $deal->organization_id = $organizationId;
        }

        $this->syncLegacyStage($deal);
    }

    public function updating(Deal $deal): void
    {
        if ($deal->isDirty(['stage', 'pipeline_stage_id'])) {
            $this->syncLegacyStage($deal);
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
     * `pipeline_stage_id` is the source of truth: `deals.stage` (still read by the segment conditions until P2 slice 3)
     * is always overwritten from it, so a write of `stage` alone is reverted. Skipped without an organization or a
     * stage id (the NOT NULL constraints then refuse the row); a stage of another organization is not found.
     */
    private function syncLegacyStage(Deal $deal): void
    {
        if (! $deal->organization_id || ! $deal->pipeline_stage_id) {
            return;
        }

        $stage = PipelineStage::forOrganization($deal->organization_id)->findOrFail($deal->pipeline_stage_id);

        $deal->stage = DefaultPipeline::legacyStageFor($stage);
    }
}
