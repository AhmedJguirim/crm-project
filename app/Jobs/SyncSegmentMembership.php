<?php

namespace App\Jobs;

use App\Exceptions\RulesChangedDuringSync;
use App\Filament\Resources\Segments\SegmentResource;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Segment;
use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recomputes the members of a published segment with set-based statements: contacts that stopped matching are
 * removed, new matches are added, and contacts that still match keep their original "joined at" date.
 *
 * It runs on the `segments` queue, apart from the quick jobs, because it can take minutes on big segments.
 *
 * The segment row is only locked while the result is applied, never while it is computed, so editing the rules of a
 * segment that is syncing doesn't wait for the sync.
 *
 * Only one sync per segment runs at a time: a sync that finds another one running is released and retried for up
 * to 30 minutes, and its overlap lock expires shortly after the job timeout so that a killed worker can't block the
 * segment. The rules are read under a row lock, in the same transaction that writes the members, so a sync
 * always applies the rules stored when it writes.
 */
class SyncSegmentMembership implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Must stay below the overlap lock expiry, which stays within the `segments` supervisor timeout of Horizon, which
     * in turn stays below the `retry_after` of the redis queue connection: a sync running longer than `retry_after`
     * would be handed to a second worker while the first one is still writing.
     */
    public int $timeout = 600;

    /**
     * A killed worker never releases the job or the overlap lock, so a timeout must fail the job right away: `failed()`
     * then resets `is_syncing`. Without it the job would be retried until `retryUntil()` and the segment would show
     * as syncing for half an hour.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $segmentId,
        public readonly ?int $notifyUserId = null,
    ) {
        $this->onQueue('segments');
        $this->onConnection(config('queue.long_running_connection'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->segmentId))
                ->releaseAfter(15)
                ->expireAfter(660),
            new WithTenantContext(fn (): ?int => Segment::query()
                ->withoutGlobalScope('organization')
                ->whereKey($this->segmentId)
                ->value('organization_id')),
        ];
    }

    /**
     * Time-based on purpose: the queue worker runs with `--tries=1` and every release caused by the overlap
     * middleware counts as an attempt, so a waiting sync would otherwise fail instead of being retried.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function uniqueId(): string
    {
        return (string) $this->segmentId;
    }

    public function handle(): void
    {
        try {
            $segment = DB::transaction(fn (): ?Segment => $this->computeAndApply(now()));
        } catch (RulesChangedDuringSync) {
            self::dispatch($this->segmentId, $this->notifyUserId);

            return;
        }

        if ($segment) {
            $this->notify($segment);
        }
    }

    /**
     * Phase 1 evaluates the rules over all the contacts into a temporary table, without locking the segment: the
     * rule editor saves its drafts on the same row and must not wait for it. Phase 2 locks the row, checks that the
     * published rules are still the ones evaluated, and applies the result. If they changed, nothing is applied (the
     * transaction is rolled back) and the caller queues a new sync.
     *
     * Raw SQL is used because the query builder has no temporary tables, no anti-join (`NOT EXISTS` plans much better
     * than `NOT IN (subquery)` on large segments) and no `INSERT … SELECT … ON CONFLICT` with a table of its own.
     * The table is dropped when the transaction ends; `WithoutOverlapping` keeps one sync per segment running.
     *
     * @throws RulesChangedDuringSync
     */
    private function computeAndApply(CarbonInterface $now): ?Segment
    {
        $segment = Segment::query()->withoutGlobalScope('organization')->find($this->segmentId);

        if (! $segment || ! $segment->is_published) {
            return null;
        }

        $versionRead = $segment->rules_version;

        $matching = $segment->queryBuilder()
            ->matching($segment->publishedRules())
            ->select('contacts.id');

        DB::statement('DROP TABLE IF EXISTS segment_sync_matches');
        DB::statement('CREATE TEMPORARY TABLE segment_sync_matches (contact_id bigint PRIMARY KEY) ON COMMIT DROP');
        DB::insert("INSERT INTO segment_sync_matches (contact_id) {$matching->toSql()} ON CONFLICT DO NOTHING", $matching->getBindings());
        DB::statement('ANALYZE segment_sync_matches');

        $locked = Segment::query()->withoutGlobalScope('organization')->lockForUpdate()->find($this->segmentId);

        if (! $locked || ! $locked->is_published || $locked->rules_version !== $versionRead) {
            throw new RulesChangedDuringSync;
        }

        DB::delete(
            'DELETE FROM contact_segment cs WHERE cs.segment_id = ? AND NOT EXISTS (SELECT 1 FROM segment_sync_matches m WHERE m.contact_id = cs.contact_id)',
            [$locked->id],
        );

        DB::insert(
            'INSERT INTO contact_segment (segment_id, contact_id, created_at, updated_at) SELECT ?, m.contact_id, ?, ? FROM segment_sync_matches m ON CONFLICT (segment_id, contact_id) DO NOTHING',
            [$locked->id, $now, $now],
        );

        $locked->update([
            'is_syncing' => false,
            'last_synced_at' => $now,
        ]);

        return $locked;
    }

    public function failed(?Throwable $exception): void
    {
        Segment::query()->withoutGlobalScopes()->whereKey($this->segmentId)->update(['is_syncing' => false]);
    }

    private function notify(Segment $segment): void
    {
        $user = $this->notifyUserId ? User::find($this->notifyUserId) : null;

        if (! $user) {
            return;
        }

        $count = $segment->contacts()->count();

        Notification::make()
            ->success()
            ->title('Segment setup complete')
            ->body("'{$segment->name}' now has {$count} ".str('contact')->plural($count).'.')
            ->actions([
                Action::make('view')
                    ->label('View segment')
                    ->url(SegmentResource::getUrl('view', ['record' => $segment], panel: 'admin', tenant: $segment->organization)),
            ])
            ->sendToDatabase($user);
    }
}
