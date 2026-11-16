<?php

namespace App\Observers;

use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\Segment;
use App\Services\Deals\DefaultPipeline;
use App\Support\UserFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizationObserver
{
    /**
     * Every organization starts with the default pipeline (P2), whichever path creates it.
     */
    public function created(Organization $organization): void
    {
        DefaultPipeline::ensureFor($organization);
    }

    /**
     * Segments with date conditions change membership when the organization's day starts at another time, so the
     * published ones with conditions on timestamps (the Created Date) or relative to today are synced again.
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
            ->filter(fn (Segment $segment): bool => $segment->dependsOnTimezone())
            ->each(fn (Segment $segment) => SyncSegmentMembership::dispatch($segment->id));
    }

    /**
     * When an organization is removed for good (the purge, or the account deletion of its only member), its export and
     * failed-rows files and its logo go with it, once the deletion is committed.
     */
    public function forceDeleted(Organization $organization): void
    {
        $organizationId = $organization->getKey();
        $logoPath = $organization->logo_path;

        DB::afterCommit(function () use ($organizationId, $logoPath): void {
            UserFiles::deleteForOrganization($organizationId);

            if (is_string($logoPath) && $logoPath !== '') {
                Storage::disk('local')->delete($logoPath);
            }
        });
    }
}
