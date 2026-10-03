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
 *
 * The rules are read before the contact is evaluated and the memberships are written afterwards, so a segment whose
 * published rules were replaced in between must be left alone: the full sync queued by that publish is authoritative
 * and would otherwise be overwritten with a membership computed from the old rules. Each segment's `rules_version`
 * is compared at write time, under a share lock, which lets other jobs read the segments but makes a concurrent
 * publish wait for this short write, so that its full sync runs after it.
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

        /** @var array<int, int> $versionsAtStart */
        $versionsAtStart = $segments->pluck('rules_version', 'id')->all();

        $matchingSegmentIds = $contact->trashed() ? [] : $this->matchingSegmentIds($contact, $segments);
        $staleSegmentIds = array_values(array_diff($segments->modelKeys(), $matchingSegmentIds));

        DB::transaction(function () use ($contact, $matchingSegmentIds, $staleSegmentIds, $versionsAtStart): void {
            $unchanged = $this->segmentsWithUnchangedRules($versionsAtStart);

            if ($unchanged === []) {
                return;
            }

            $matchingSegmentIds = array_values(array_intersect($matchingSegmentIds, $unchanged));
            $staleSegmentIds = array_values(array_intersect($staleSegmentIds, $unchanged));

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
     * The segments that are still published with the rules the contact was evaluated against, locked against a
     * concurrent publish until the surrounding transaction ends.
     *
     * @param  array<int, int>  $versionsAtStart  Rules version by segment ID, as read before the evaluation.
     * @return array<int, int>
     */
    private function segmentsWithUnchangedRules(array $versionsAtStart): array
    {
        $current = Segment::query()
            ->withoutGlobalScope('organization')
            ->whereKey(array_keys($versionsAtStart))
            ->where('is_published', true)
            ->sharedLock()
            ->pluck('rules_version', 'id');

        return array_keys(array_filter(
            $versionsAtStart,
            fn (int $version, int $segmentId): bool => ($current[$segmentId] ?? null) === $version,
            ARRAY_FILTER_USE_BOTH,
        ));
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
