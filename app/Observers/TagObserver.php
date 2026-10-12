<?php

namespace App\Observers;

use App\Enums\SegmentConditionType;
use App\Jobs\SyncSegmentMembership;
use App\Models\Segment;
use App\Models\Tag;
use App\Support\Tenancy\TenantContext;

class TagObserver
{
    public function creating(Tag $tag): void
    {
        if (! $tag->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $tag->organization_id = $organizationId;
        }
    }

    /**
     * A trashed tag no longer counts in segment conditions: recompute the segments filtering on tags.
     */
    public function deleted(Tag $tag): void
    {
        $this->resyncSegmentsFilteringOnTags($tag);
    }

    public function restored(Tag $tag): void
    {
        $this->resyncSegmentsFilteringOnTags($tag);
    }

    private function resyncSegmentsFilteringOnTags(Tag $tag): void
    {
        Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $tag->organization_id)
            ->where('is_published', true)
            ->usingConditionType(SegmentConditionType::Tags)
            ->pluck('id')
            ->each(fn (int $segmentId) => SyncSegmentMembership::dispatch($segmentId));
    }
}
