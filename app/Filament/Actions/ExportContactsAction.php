<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportContactsJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;

/**
 * The two ways to export contacts from the contacts list: everything the table shows (search, filters and sort
 * applied, across all pages), or the selected rows. Both queue an `ExportContactsJob`.
 */
class ExportContactsAction
{
    use QueuesExports;

    public static function forList(): Action
    {
        return Action::make('exportContacts')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export contacts')
            ->modalDescription('Exports the contacts the list shows now, across all pages.')
            ->schema([self::exportFormatField()])
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('contacts.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), 'contacts', ExportContactsJob::class);
            });
    }

    public static function forSelection(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export selected contacts')
            ->schema([self::exportFormatField()])
            ->action(function (Collection $records, array $data): void {
                self::queueExport($records->modelKeys(), self::exportFormatOf($data), 'contacts', ExportContactsJob::class);
            })
            ->deselectRecordsAfterCompletion();
    }
}
