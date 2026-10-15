<?php

namespace App\Observers;

use App\Enums\DealStage;
use App\Jobs\ResyncContactSegments;
use App\Models\Deal;
use App\Services\Deals\DefaultPipeline;
use App\Support\Tenancy\TenantContext;

class DealObserver
{
    /**
     * Fills the organization from the tenant context first (the trait's own listener may run after this one), then
     * derives the pipeline stage, overwriting any given one: `stage` is the source of truth until FEAT-44.
     */
    public function creating(Deal $deal): void
    {
        if (! $deal->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $deal->organization_id = $organizationId;
        }

        $this->syncPipelineStage($deal);
    }

    public function updating(Deal $deal): void
    {
        if ($deal->isDirty('stage')) {
            $this->syncPipelineStage($deal);
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
     * Skipped without an organization or a stage: the NOT NULL constraints then refuse the row, as before.
     */
    private function syncPipelineStage(Deal $deal): void
    {
        if (! $deal->organization_id || ! $deal->stage instanceof DealStage) {
            return;
        }

        $deal->pipeline_stage_id = DefaultPipeline::stageFor($deal->organization_id, $deal->stage)->getKey();
    }
}
