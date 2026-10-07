<?php

namespace App\Filament\Actions\Concerns;

use App\Enums\ExportFormat;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * What every export action shares: the format select, reading the chosen format and queueing the export job with
 * the "Export queued" or "Nothing to export." notification.
 */
trait QueuesExports
{
    protected static function exportFormatField(): Select
    {
        return Select::make('format')
            ->label('Format')
            ->options(ExportFormat::class)
            ->default(ExportFormat::Xlsx)
            ->required()
            ->helperText('CSV is UTF-8 with a BOM, separated by commas.');
    }

    /**
     * The chosen format: the select gives the case itself when it was left on its default, and its value otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function exportFormatOf(array $data): ExportFormat
    {
        $format = $data['format'];

        return $format instanceof ExportFormat ? $format : ExportFormat::from($format);
    }

    /**
     * @param  array<int, int>  $ids  In the order of the file.
     * @param  string  $fileLabel  The start of the file name the user gets, e.g. `contacts`.
     * @param  class-string  $jobClass  A job using `DeliversExport`, whose constructor takes the ids, the organization id, the
     *                                  user id, the format value and the download name, in that order.
     */
    protected static function queueExport(array $ids, ExportFormat $format, string $fileLabel, string $jobClass): void
    {
        if ($ids === []) {
            Notification::make()->warning()->title('Nothing to export.')->send();

            return;
        }

        $organization = Filament::getTenant();

        $jobClass::dispatch(
            $ids,
            $organization->getKey(),
            auth()->id(),
            $format->value,
            "{$fileLabel}-{$organization->localNow()->format('Y-m-d')}.{$format->value}",
        );

        Notification::make()
            ->success()
            ->title('Export queued')
            ->body('Your file is being built. You will receive a notification when it is ready.')
            ->send();
    }
}
