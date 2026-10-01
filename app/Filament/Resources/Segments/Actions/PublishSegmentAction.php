<?php

namespace App\Filament\Resources\Segments\Actions;

use App\Filament\Resources\Segments\SegmentResource;
use App\Jobs\SyncSegmentMembership;
use App\Models\Segment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Publishes the working definition of a segment and queues the computation of its members.
 */
class PublishSegmentAction
{
    public static function make(string $name = 'publish'): Action
    {
        return Action::make($name)
            ->label('Publish Segment')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The segment members will be computed in the background. You will be notified when they are ready.')
            ->visible(fn (Segment $record): bool => ! $record->is_published && ! $record->trashed())
            ->disabled(fn (Segment $record): bool => ! $record->canBePublished())
            ->tooltip(fn (Segment $record): ?string => $record->canBePublished() ? null : 'Add at least one complete rule to publish this segment.')
            ->action(function (Segment $record, Action $action): void {
                self::publish($record);

                $action->redirect(SegmentResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function publish(Segment $segment): void
    {
        $segment->publishWorkingRules();

        SyncSegmentMembership::dispatch($segment->id, auth()->id());

        Notification::make()
            ->title('Computing segment members')
            ->body('This may take a moment. You will be notified when the segment is ready.')
            ->info()
            ->send();
    }
}
