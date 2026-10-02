<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Segment;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        if ($segments->isEmpty()) {
            return;
        }

        $matchingSegmentIds = $contact->trashed() ? [] : $this->matchingSegmentIds($contact, $segments);
        $staleSegmentIds = array_values(array_diff($segments->modelKeys(), $matchingSegmentIds));

        DB::transaction(function () use ($contact, $matchingSegmentIds, $staleSegmentIds): void {
            $now = now();

            if ($matchingSegmentIds !== []) {
                $contact->segments()->newPivotStatement()->insertOrIgnore(
                    array_map(fn (int $segmentId): array => [
                        'segment_id' => $segmentId,
                        'contact_id' => $contact->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $matchingSegmentIds)
                );
            }

            if ($staleSegmentIds !== []) {
                $contact->segments()->newPivotStatement()
                    ->where('contact_id', $contact->id)
                    ->whereIn('segment_id', $staleSegmentIds)
                    ->delete();
            }
        });
    }

    /**
     * Evaluates every segment against the contact with a single query holding one EXISTS column per segment.
     *
     * @param  Collection<int, Segment>  $segments
     * @return array<int, int>
     */
    private function matchingSegmentIds(Contact $contact, Collection $segments): array
    {
        $builder = SegmentQueryBuilder::forOrganization($contact->organization_id);

        $query = Contact::query()
            ->withoutGlobalScope('organization')
            ->whereKey($contact->id)
            ->select('contacts.id');

        foreach ($segments as $segment) {
            $match = $builder
                ->matching($segment->publishedRules())
                ->whereKey($contact->id)
                ->select('contacts.id');

            $query->selectRaw("EXISTS ({$match->toSql()}) AS \"segment_{$segment->id}\"", $match->getBindings());
        }

        $row = $query->toBase()->first();

        return $segments
            ->filter(fn (Segment $segment): bool => (bool) ($row->{"segment_{$segment->id}"} ?? false))
            ->modelKeys();
    }
}
