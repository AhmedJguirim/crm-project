<?php

namespace App\Observers;

use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\Segment;

class OrganizationObserver
{
    /**
     * Segments with date conditions change membership when the organization's day starts at another time, so the
     * published ones that depend on the current date are synced again.
     */
    public function updated(Organization $organization): void
    {
        if (! $organization->wasChanged('timezone')) {
            return;
        }

        Segment::forOrganization($organization->getKey())
            ->where('is_published', true)
            ->select(['id', 'rules'])
            ->get()
            ->filter(fn (Segment $segment): bool => $segment->refreshFrequency() !== null)
            ->each(fn (Segment $segment) => SyncSegmentMembership::dispatch($segment->id));
    }
}
