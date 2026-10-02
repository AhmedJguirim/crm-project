<?php

namespace App\Jobs;

use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Segment;
use App\Models\User;
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
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->segmentId))
                ->releaseAfter(15)
                ->expireAfter(660),
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
        $now = now();

        $segment = DB::transaction(function () use ($now): ?Segment {
            $segment = Segment::query()
                ->withoutGlobalScope('organization')
                ->lockForUpdate()
                ->find($this->segmentId);

            if (! $segment || ! $segment->is_published) {
                return null;
            }

            $matchingContactIds = $segment->queryBuilder()
                ->matching($segment->publishedRules())
                ->select('contacts.id');

            $segment->contacts()->newPivotStatement()
                ->where('segment_id', $segment->id)
                ->whereNotIn('contact_id', $matchingContactIds->clone())
                ->delete();

            $segment->contacts()->newPivotStatement()->insertOrIgnoreUsing(
                ['segment_id', 'contact_id', 'created_at', 'updated_at'],
                $matchingContactIds->clone()
                    ->select([])
                    ->selectRaw('?, contacts.id, ?, ?', [$segment->id, $now, $now])
                    ->toBase(),
            );

            $segment->update([
                'is_syncing' => false,
                'last_synced_at' => $now,
            ]);

            return $segment;
        });

        if ($segment) {
            $this->notify($segment);
        }
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
