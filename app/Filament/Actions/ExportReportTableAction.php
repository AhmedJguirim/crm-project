<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Support\Reports\DashboardFilters;
use App\Support\TemporaryFile;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The "Export" button of a Reports page table: admins and the owner download the rows the table shows now (same
 * period and owner filters) as an .xlsx or a CSV. The file is built in the request and returned as the download of the
 * authenticated user who clicked, because these tables are small and the report services read the panel tenant, so
 * they cannot run in a queued job; no URL is created and no file is stored.
 */
class ExportReportTableAction
{
    use QueuesExports;

    /**
     * @param  string  $fileLabel  The start of the file name, e.g. `team-activity`.
     * @param  string  $tableName  The table in the modal heading, e.g. `team activity`.
     * @param  Closure(): array{headers: list<string>, rows: list<list<string|int|float>>}  $sheet
     */
    public static function make(string $fileLabel, string $tableName, DashboardFilters $filters, Closure $sheet): Action
    {
        return Action::make('exportTable')
            ->authorize(fn (): bool => Gate::allows('exportReports', CurrentOrganization::find()))
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading("Export {$tableName}")
            ->modalDescription('Exports the rows the table shows now, with the period and owner filters applied.')
            ->schema([self::exportFormatField()])
            ->action(function (array $data) use ($fileLabel, $filters, $sheet): ?BinaryFileResponse {
                $format = self::exportFormatOf($data);
                $content = $sheet();

                if ($content['rows'] === []) {
                    Notification::make()->warning()->title('Nothing to export.')->send();

                    return null;
                }

                [$from, $to] = $filters->period->dates();
                $path = TemporaryFile::reserve("{$fileLabel}-export-", $format->value);

                try {
                    ExportFileWriter::write(
                        $path,
                        ExportCells::headers($content['headers'], $format),
                        [$content['rows']],
                        fn (array $cells): array => ExportCells::row($cells, $format),
                    );
                } catch (Throwable $exception) {
                    @unlink($path);

                    throw $exception;
                }

                return response()
                    ->download($path, "{$fileLabel}-{$from}-to-{$to}.{$format->value}", ['Content-Type' => $format->contentType()])
                    ->deleteFileAfterSend();
            });
    }
}
