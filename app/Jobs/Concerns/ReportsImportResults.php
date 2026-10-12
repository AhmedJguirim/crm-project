<?php

namespace App\Jobs\Concerns;

use App\Services\ContactImportService;
use App\Support\CsvDialect;
use App\Support\TemporaryFile;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * What every import job reports back to the user: the failed rows file, its signed download link and the columns that
 * were ignored. The job using it must have the `$filePath` (the uploaded file on the local disk) and `$userId`
 * (the user who started the import) properties.
 */
trait ReportsImportResults
{
    private const MAX_IGNORED_COLUMNS_LISTED = 10;

    /**
     * The line naming the columns that matched no field, at most {@see self::MAX_IGNORED_COLUMNS_LISTED} of them.
     *
     * @param  array<int, string>  $ignoredColumns
     */
    protected function ignoredColumnsLine(array $ignoredColumns): ?string
    {
        if ($ignoredColumns === []) {
            return null;
        }

        $listed = implode(', ', array_slice($ignoredColumns, 0, self::MAX_IGNORED_COLUMNS_LISTED));
        $more = count($ignoredColumns) - self::MAX_IGNORED_COLUMNS_LISTED;

        return 'Ignored columns (no matching field): '.$listed.($more > 0 ? ", … and {$more} more" : '');
    }

    protected function failedRowsAction(string $failedCsvPath): Action
    {
        return Action::make('downloadFailedRows')
            ->label('Download failed rows')
            ->url(URL::temporarySignedRoute(
                'contacts.import.failed-rows',
                now()->addDays(7),
                ['file' => basename($failedCsvPath), 'user' => $this->userId],
            ))
            ->openUrlInNewTab();
    }

    /**
     * Copies the uploaded file to a local temporary file that keeps its extension, as the stored disk may not be local.
     */
    protected function copyToTemporaryFile(string $extension, string $prefix): string
    {
        $tmpPath = TemporaryFile::reserve($prefix, $extension);

        file_put_contents($tmpPath, Storage::disk('local')->get($this->filePath));

        return $tmpPath;
    }

    /**
     * Written with the delimiter of the imported file, in UTF-8 with a BOM, so the user can fix it in the same Excel
     * and import it again as is.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array{row: int, data: array<string, string>, error: string}>  $failedRows
     */
    protected function storeFailedRowsCsv(array $headers, array $failedRows, CsvDialect $dialect): string
    {
        $tmpPath = TemporaryFile::reserve('failed-rows-', 'csv');

        $headers = array_values(array_filter(
            $headers,
            fn (string $header): bool => ! in_array(mb_strtolower(trim($header)), ContactImportService::FAILED_ROWS_META_COLUMNS, true),
        ));

        try {
            $writer = SimpleExcelWriter::create($tmpPath, delimiter: $dialect->delimiter)
                ->noHeaderRow()
                ->addRow([...ContactImportService::FAILED_ROWS_META_COLUMNS, ...$headers]);

            foreach ($failedRows as $failedRow) {
                $writer->addRow([
                    $failedRow['row'],
                    $failedRow['error'],
                    ...array_map(fn (string $header): string => $failedRow['data'][$header] ?? '', $headers),
                ]);
            }

            $writer->close();

            $path = 'contact-imports/failed-'.Str::random(40).'.csv';

            Storage::disk('local')->put($path, file_get_contents($tmpPath));
        } finally {
            @unlink($tmpPath);
        }

        return $path;
    }
}
