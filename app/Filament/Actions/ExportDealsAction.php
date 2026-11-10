<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportDealsJob;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The two ways to export deals from the deals list: everything the table shows (tab, search, filters and sort
 * applied, across all pages), or the selected rows. Both queue an `ExportDealsJob`.
 */
class ExportDealsAction
{
    use QueuesExports;

    public static function forList(): Action
    {
        return Action::make('exportDeals')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export deals')
            ->modalDescription('Exports the deals the list shows now, across all pages.')
            ->schema([self::exportFormatField()])
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('deals.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), 'deals', ExportDealsJob::class);
            });
    }

    public static function forSelection(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export selected deals')
            ->schema([self::exportFormatField()])
            ->action(function (Collection $records, array $data): void {
                /** @var EloquentCollection<int, Deal> $records */
                self::queueExport($records->modelKeys(), self::exportFormatOf($data), 'deals', ExportDealsJob::class);
            })
            ->deselectRecordsAfterCompletion();
    }
}
