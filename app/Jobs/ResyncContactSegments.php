<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Segment;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-evaluates a single contact against every published segment of its organization after the contact changed.
 */
class ResyncContactSegments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $contactId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->contactId;
    }

    /**
     * Queue a re-evaluation of the given contacts, unless their organization has no published segment.
     *
     * @param  array<int, int|null>  $contactIds
     */
    public static function dispatchForContacts(?int $organizationId, array $contactIds): void
    {
        $contactIds = array_unique(array_filter($contactIds));

        if ($organizationId === null || $contactIds === []) {
            return;
        }

        $hasPublishedSegments = Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_published', true)
            ->exists();

        if (! $hasPublishedSegments) {
            return;
        }

        foreach ($contactIds as $contactId) {
            static::dispatch($contactId);
        }
    }

    public function handle(): void
    {
        $contact = Contact::query()->withoutGlobalScopes()->find($this->contactId);

        if (! $contact) {
            return;
        }

        $segments = Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $contact->organization_id)
            ->where('is_published', true)
            ->get();

        $builder = SegmentQueryBuilder::forOrganization($contact->organization_id);

        $matchingSegmentIds = $segments
            ->filter(fn (Segment $segment): bool => ! $contact->trashed() && $builder
                ->matching($segment->publishedRules())
                ->whereKey($contact->getKey())
                ->exists())
            ->modelKeys();

        $staleSegmentIds = array_values(array_diff($segments->modelKeys(), $matchingSegmentIds));

        $contact->segments()->syncWithoutDetaching($matchingSegmentIds);

        if ($staleSegmentIds !== []) {
            $contact->segments()->detach($staleSegmentIds);
        }
    }
}
