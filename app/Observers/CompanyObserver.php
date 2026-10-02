<?php

namespace App\Observers;

use App\Enums\SegmentConditionType;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\Segment;

/**
 * Changing the type of a company, or trashing or restoring it, moves its contacts in and out of the segments
 * with company conditions: recompute those segments.
 */
class CompanyObserver
{
    public function updated(Company $company): void
    {
        if (! $company->wasChanged('company_type_id')) {
            return;
        }

        $this->resyncSegmentsFilteringOnCompanies($company);
    }

    public function deleted(Company $company): void
    {
        $this->resyncSegmentsFilteringOnCompanies($company);
    }

    public function restored(Company $company): void
    {
        $this->resyncSegmentsFilteringOnCompanies($company);
    }

    private function resyncSegmentsFilteringOnCompanies(Company $company): void
    {
        Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $company->organization_id)
            ->where('is_published', true)
            ->usingConditionType(SegmentConditionType::Company)
            ->pluck('id')
            ->each(fn (int $segmentId) => SyncSegmentMembership::dispatch($segmentId));
    }
}
