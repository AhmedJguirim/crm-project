<?php

namespace App\Jobs;

use App\Exceptions\UnreadableImportFileException;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use App\Support\CsvDialect;
use App\Support\TemporaryFile;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

/**
 * Imports the contacts of an uploaded CSV or Excel (.xlsx) file. Only the first worksheet is read.
 *
 * Excel drops empty trailing cells, so shorter Excel rows are padded to the header length. CSV rows must match it.
 *
 * It runs on the `imports` queue, apart from the quick jobs, because a large file takes a long time.
 *
 * Imports of one organization run one at a time (the row checks and the tag creation are not safe against a
 * concurrent import of the same organization); the others wait, for up to three hours. Imports of other
 * organizations are not blocked.
 */
class ProcessContactImportJob implements ShouldQueue
{
    use Queueable;

    private const MAX_IGNORED_COLUMNS_LISTED = 10;

    /**
     * Just under the timeout of the `imports` Horizon supervisor, so a very long import fails cleanly before the
     * worker is killed.
     */
    public int $timeout = 1740;

    /**
     * With `retryUntil()` a timed-out job is otherwise not failed but retried for the whole window, and the worker
     * is killed, so `failed()` would never tell the user.
     */
    public bool $failOnTimeout = true;

    /**
     * Same reason: an unexpected exception must fail the import right away instead of retrying it for hours. Waiting
     * for the overlap lock is a release, not an exception, so it is not affected.
     */
    public int $maxExceptions = 1;

    public function __construct(
        private readonly string $filePath,
        private readonly int $organizationId,
        private readonly int $userId
    ) {
        $this->onQueue('imports');
        $this->onConnection(config('queue.long_running_connection'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("organization-{$this->organizationId}"))
                ->releaseAfter(30)
                ->expireAfter(1800),
            new WithTenantContext($this->organizationId),
        ];
    }

    /**
     * Time-based on purpose: the `imports` supervisor runs with `tries = 1` and every release caused by the overlap
     * middleware counts as an attempt, so a waiting import would otherwise fail instead of being retried.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(ContactImportFileReader $reader): void
    {
        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        $tmpPath = $this->copyToTemporaryFile($extension);

        $dialect = $reader->dialect($tmpPath, $extension);
        $service = new ContactImportService($this->organizationId, $dialect);

        $customFieldsByName = $service->customFields()->keyBy('name')->all();

        $headers = null;
        $canonicalHeaders = null;

        /** @var array<int, string> $ignoredColumns */
        $ignoredColumns = [];
        $importedCount = 0;
        $failedCount = 0;

        /** @var array<int, array{row: int, data: array<string, string>, error: string}> $failedRows */
        $failedRows = [];
        $rowNumber = 0;

        try {
            foreach ($reader->rows($tmpPath, $extension, $dialect) as $row) {
                if ($headers === null) {
                    $headers = $row;
                    $canonicalHeaders = $service->canonicalHeaders($headers);
                    $ignoredColumns = $service->ignoredColumns($headers);

                    $duplicated = $service->duplicatedColumn($headers);

                    if ($duplicated !== null) {
                        throw UnreadableImportFileException::duplicateColumn($duplicated);
                    }

                    continue;
                }

                $rowNumber++;

                if ($extension === 'xlsx') {
                    $row = array_pad($row, count($headers), '');
                }

                if (count($row) !== count($headers)) {
                    $failedCount++;
                    $failedRows[] = [
                        'row' => $rowNumber,
                        'data' => array_combine($headers, array_slice(array_pad($row, count($headers), ''), 0, count($headers))),
                        'error' => 'Column count mismatch.',
                    ];

                    continue;
                }

                $rowData = array_combine($headers, $row);
                $result = $service->processRow(array_combine($canonicalHeaders, $row), $customFieldsByName);

                if ($result['success']) {
                    $importedCount++;
                } else {
                    $failedCount++;
                    $failedRows[] = [
                        'row' => $rowNumber,
                        'data' => $rowData,
                        'error' => $result['error'] ?? 'Unknown error.',
                    ];
                }
            }
        } catch (UnreadableImportFileException $exception) {
            $this->handleUnreadableFile($exception, $importedCount, $failedRows, $headers, $rowNumber, $ignoredColumns, $dialect, $service);

            return;
        } finally {
            @unlink($tmpPath);
        }

        Storage::disk('local')->delete($this->filePath);

        if ($importedCount > 0) {
            $this->syncPublishedSegments();
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $ignoredLine = $this->ignoredColumnsLine($ignoredColumns);
        $companyLines = $this->companyLines($service);

        if ($failedCount === 0) {
            $notification = $ignoredLine === null
                ? Notification::make()->success()->title('Import complete')
                : Notification::make()->warning()->title('Import complete, some columns were ignored');

            $notification
                ->body(implode("\n\n", array_filter(["Imported: {$importedCount} | Failed: 0", ...$companyLines, $ignoredLine])))
                ->sendToDatabase($user);

            return;
        }

        $failedCsvPath = $this->storeFailedRowsCsv($headers, $failedRows, $dialect);

        $errorSummary = collect($failedRows)
            ->take(5)
            ->map(fn ($r) => "Row {$r['row']}: {$r['error']}")
            ->implode("\n");

        Notification::make()
            ->warning()
            ->title('Import complete with errors')
            ->body(implode("\n\n", array_filter(["Imported: {$importedCount} | Failed: {$failedCount}", ...$companyLines, $ignoredLine, $errorSummary])))
            ->actions([$this->failedRowsAction($failedCsvPath)])
            ->sendToDatabase($user);
    }

    /**
     * The lines about the companies of the file: how many were created, and that the details of existing ones were
     * left alone.
     *
     * @return array<int, string>
     */
    private function companyLines(ContactImportService $service): array
    {
        return array_values(array_filter([
            $service->createdCompaniesCount() > 0 ? "Companies created: {$service->createdCompaniesCount()}" : null,
            $service->companyDetailsIgnored() ? 'Company details were not changed for existing companies.' : null,
        ]));
    }

    /**
     * The line naming the columns that matched no field, at most {@see self::MAX_IGNORED_COLUMNS_LISTED} of them.
     *
     * @param  array<int, string>  $ignoredColumns
     */
    private function ignoredColumnsLine(array $ignoredColumns): ?string
    {
        if ($ignoredColumns === []) {
            return null;
        }

        $listed = implode(', ', array_slice($ignoredColumns, 0, self::MAX_IGNORED_COLUMNS_LISTED));
        $more = count($ignoredColumns) - self::MAX_IGNORED_COLUMNS_LISTED;

        return 'Ignored columns (no matching field): '.$listed.($more > 0 ? ", … and {$more} more" : '');
    }

    private function failedRowsAction(string $failedCsvPath): Action
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
    private function copyToTemporaryFile(string $extension): string
    {
        $tmpPath = TemporaryFile::reserve('contact-import-', $extension);

        file_put_contents($tmpPath, Storage::disk('local')->get($this->filePath));

        return $tmpPath;
    }

    /**
     * The file became unreadable: what was imported before is kept, so when rows were handled the user is told how far
     * the import went instead of a plain failure.
     *
     * @param  array<int, array{row: int, data: array<string, string>, error: string}>  $failedRows
     * @param  array<int, string>|null  $headers
     * @param  array<int, string>  $ignoredColumns
     */
    private function handleUnreadableFile(UnreadableImportFileException $exception, int $importedCount, array $failedRows, ?array $headers, int $lastRowRead, array $ignoredColumns, CsvDialect $dialect, ContactImportService $service): void
    {
        Storage::disk('local')->delete($this->filePath);

        if ($importedCount > 0) {
            $this->syncPublishedSegments();
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        if ($importedCount === 0 && $failedRows === []) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body($exception->userMessage ?? "We couldn't read this file. Upload a CSV or an Excel (.xlsx) file whose first sheet starts with a header row.")
                ->sendToDatabase($user);

            return;
        }

        $failedCount = count($failedRows);

        $notification = Notification::make()
            ->warning()
            ->title('Import stopped partway')
            ->body(implode("\n\n", array_filter([
                "Imported: {$importedCount} | Failed: {$failedCount}. The file could not be read after row {$lastRowRead}, so the rest of it was not imported. The contacts above were kept; import the remaining rows in a new file.",
                ...$this->companyLines($service),
                $this->ignoredColumnsLine($ignoredColumns),
            ])));

        if ($failedRows !== [] && $headers !== null) {
            $notification->actions([$this->failedRowsAction($this->storeFailedRowsCsv($headers, $failedRows, $dialect))]);
        }

        $notification->sendToDatabase($user);
    }

    /**
     * The import crashed or timed out: the contacts created so far are kept, so their segments are synced, and the
     * user is told, because nothing else would.
     */
    public function failed(?Throwable $exception): void
    {
        Storage::disk('local')->delete($this->filePath);

        $this->syncPublishedSegments();

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Import failed')
            ->body('Something went wrong while importing your file. Contacts already imported were kept; you can upload the file again, existing contacts will be reported as already existing.')
            ->sendToDatabase($user);
    }

    private function syncPublishedSegments(): void
    {
        Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $this->organizationId)
            ->where('is_published', true)
            ->pluck('id')
            ->each(fn (int $segmentId) => SyncSegmentMembership::dispatch($segmentId));
    }

    /**
     * Written with the delimiter of the imported file, in UTF-8 with a BOM, so the user can fix it in the same Excel
     * and import it again as is.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array{row: int, data: array<string, string>, error: string}>  $failedRows
     */
    private function storeFailedRowsCsv(array $headers, array $failedRows, CsvDialect $dialect): string
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
