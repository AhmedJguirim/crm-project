<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportCompaniesJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;

/**
 * The two ways to export companies from the companies list: everything the table shows (search, filters and sort
 * applied, across all pages), or the selected rows. Both queue an `ExportCompaniesJob`.
 */
class ExportCompaniesAction
{
    use QueuesExports;

    public static function forList(): Action
    {
        return Action::make('exportCompanies')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export companies')
            ->modalDescription('Exports the companies the list shows now, across all pages.')
            ->schema([self::exportFormatField()])
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('companies.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), 'companies', ExportCompaniesJob::class);
            });
    }

    public static function forSelection(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export selected companies')
            ->schema([self::exportFormatField()])
            ->action(function (Collection $records, array $data): void {
                self::queueExport($records->modelKeys(), self::exportFormatOf($data), 'companies', ExportCompaniesJob::class);
            })
            ->deselectRecordsAfterCompletion();
    }
}
