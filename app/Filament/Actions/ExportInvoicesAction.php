<?php

namespace App\Filament\Actions;

use App\Enums\ExportFormat;
use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportInvoicesJob;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The two ways to export invoices with their payments from the invoices list: everything the table shows (filters,
 * search and sort applied, across all pages), or the selected rows. Both queue an `ExportInvoicesJob`; a CSV export is
 * downloaded as a ZIP.
 */
class ExportInvoicesAction
{
    use QueuesExports;

    public static function forList(): Action
    {
        return Action::make('exportInvoices')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export invoices')
            ->modalDescription('Exports the invoices the list shows now, across all pages, with their payments.')
            ->schema([self::invoicesFormatField()])
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('invoices.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueInvoicesExport($ids, $data);
            });
    }

    public static function forSelection(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export selected invoices')
            ->schema([self::invoicesFormatField()])
            ->action(function (Collection $records, array $data): void {
                /** @var EloquentCollection<int, Invoice> $records */
                self::queueInvoicesExport($records->modelKeys(), $data);
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function invoicesFormatField(): Select
    {
        return self::exportFormatField()->helperText('CSV comes as a ZIP of invoices.csv and payments.csv, UTF-8 with a BOM, separated by commas. Excel has an Invoices sheet and a Payments sheet.');
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $data
     */
    private static function queueInvoicesExport(array $ids, array $data): void
    {
        $format = self::exportFormatOf($data);

        self::queueExport($ids, $format, 'invoices', ExportInvoicesJob::class, $format === ExportFormat::Csv ? 'zip' : null);
    }
}
