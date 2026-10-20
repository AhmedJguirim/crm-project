<?php

namespace App\Observers;

use App\Enums\DealStatus;
use App\Jobs\ResyncContactSegments;
use App\Models\Deal;
use App\Models\LostReason;
use App\Models\PipelineStage;
use App\Services\Deals\DealStageMover;
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
        $this->applyLostReasonRules($deal);
    }

    public function updating(Deal $deal): void
    {
        if ($deal->isDirty('pipeline_stage_id')) {
            $this->ensureStageOfOrganization($deal);
        }

        $this->applyLostReasonRules($deal);
    }

    public function created(Deal $deal): void
    {
        DealStageMover::recordStageChange($deal, null, $deal->created_at ?? now());
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('pipeline_stage_id')) {
            $original = $deal->getOriginal('pipeline_stage_id');

            DealStageMover::recordStageChange($deal, $original === null ? null : (int) $original, now());
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

    /**
     * The lost reason and note only live on a Lost deal: any other status clears both, so reopening a deal (or a forged
     * value on an open one) leaves nothing behind. On a Lost deal a changed reason must be one of the organization's
     * (a deleted one is still accepted, it is only the choice in the forms that hides it): another organization's id is
     * not found.
     *
     * @throws ModelNotFoundException
     */
    private function applyLostReasonRules(Deal $deal): void
    {
        if ($deal->status !== DealStatus::Lost) {
            $deal->lost_reason_id = null;
            $deal->lost_reason_note = null;

            return;
        }

        if ($deal->lost_reason_id !== null && $deal->isDirty('lost_reason_id')) {
            LostReason::withTrashed()->forOrganization($deal->organization_id)->findOrFail($deal->lost_reason_id);
        }
    }
}
