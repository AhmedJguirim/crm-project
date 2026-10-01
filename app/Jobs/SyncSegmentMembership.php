<?php

namespace App\Jobs;

use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Segment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recomputes the members of a published segment with set-based statements: contacts that stopped matching are
 * removed, new matches are added, and contacts that still match keep their original "joined at" date.
 */
class SyncSegmentMembership implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $segmentId,
        public readonly ?int $notifyUserId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->segmentId;
    }

    public function handle(): void
    {
        $segment = Segment::query()->withoutGlobalScope('organization')->find($this->segmentId);

        if (! $segment || ! $segment->is_published) {
            return;
        }

        $matchingContactIds = $segment->queryBuilder()
            ->matching($segment->publishedRules())
            ->select('contacts.id');

        $now = now();

        DB::transaction(function () use ($segment, $matchingContactIds, $now): void {
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
        });

        $segment->update([
            'is_syncing' => false,
            'last_synced_at' => $now,
        ]);

        $this->notify($segment);
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
