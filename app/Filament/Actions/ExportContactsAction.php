<?php

namespace App\Filament\Actions;

use App\Enums\ExportFormat;
use App\Jobs\ExportContactsJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;

/**
 * The two ways to export contacts from the contacts list: everything the table shows (search, filters and sort
 * applied, across all pages), or the selected rows. Both queue an `ExportContactsJob`.
 */
class ExportContactsAction
{
    public static function forList(): Action
    {
        return Action::make('exportContacts')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export contacts')
            ->modalDescription('Exports the contacts the list shows now, across all pages.')
            ->schema(self::schema())
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('contacts.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queue($ids, self::formatOf($data));
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
            ->schema(self::schema())
            ->action(function (Collection $records, array $data): void {
                self::queue($records->modelKeys(), self::formatOf($data));
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The chosen format: the select gives the case itself when it was left on its default, and its value otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private static function formatOf(array $data): ExportFormat
    {
        $format = $data['format'];

        return $format instanceof ExportFormat ? $format : ExportFormat::from($format);
    }

    /**
     * @return array<int, Select>
     */
    private static function schema(): array
    {
        return [
            Select::make('format')
                ->label('Format')
                ->options(ExportFormat::class)
                ->default(ExportFormat::Xlsx)
                ->required()
                ->helperText('CSV is UTF-8 with a BOM, separated by commas.'),
        ];
    }

    /**
     * @param  array<int, int>  $contactIds
     */
    private static function queue(array $contactIds, ExportFormat $format): void
    {
        if ($contactIds === []) {
            Notification::make()->warning()->title('Nothing to export.')->send();

            return;
        }

        $organization = Filament::getTenant();

        ExportContactsJob::dispatch(
            $contactIds,
            $organization->getKey(),
            auth()->id(),
            $format->value,
            "contacts-{$organization->localNow()->format('Y-m-d')}.{$format->value}",
        );

        Notification::make()
            ->success()
            ->title('Export queued')
            ->body('Your file is being built. You will receive a notification when it is ready.')
            ->send();
    }
}
