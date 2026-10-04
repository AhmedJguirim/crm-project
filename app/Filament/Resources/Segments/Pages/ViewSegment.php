<?php

namespace App\Filament\Resources\Segments\Pages;

use App\Filament\Resources\Segments\Actions\PublishSegmentAction;
use App\Filament\Resources\Segments\Actions\SegmentDeletionActions;
use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Resources\Segments\Widgets\SegmentStatsOverview;
use App\Models\Segment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Segment $record
 */
class ViewSegment extends ViewRecord
{
    protected static string $resource = SegmentResource::class;

    protected string $view = 'filament.resources.segments.pages.view-segment';

    /**
     * Polled while the segment members are being computed; reloads the page once they are ready.
     */
    public function checkSyncStatus(): void
    {
        if ($this->record->refresh()->is_syncing) {
            return;
        }

        Notification::make()
            ->title('Segment setup complete')
            ->success()
            ->send();

        $this->redirect(SegmentResource::getUrl('view', ['record' => $this->record]), navigate: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            PublishSegmentAction::make(),

            Action::make('editRules')
                ->authorize('update')
                ->label('Edit rules')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->disabled(fn (Segment $record): bool => $record->is_syncing)
                ->url(fn (Segment $record): string => SegmentResource::getUrl('rules', ['record' => $record])),

            SegmentDeletionActions::delete(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SegmentStatsOverview::class,
        ];
    }
}
