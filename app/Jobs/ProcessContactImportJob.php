<?php

namespace App\Jobs;

use App\Enums\ImportMode;
use App\Exceptions\UnreadableImportFileException;
use App\Jobs\Concerns\ReportsImportResults;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use App\Support\CsvDialect;
use DateTimeInterface;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
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
    use ReportsImportResults;

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
        private readonly int $userId,
        private readonly ImportMode $mode = ImportMode::CreateOnly,
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
        $tmpPath = $this->copyToTemporaryFile($extension, 'contact-import-');

        $dialect = $reader->dialect($tmpPath, $extension);
        $service = new ContactImportService($this->organizationId, $dialect, $this->mode);

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
                ->body(implode("\n\n", array_filter([$this->countsLine($importedCount, 0, $service), ...$companyLines, $ignoredLine])))
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
            ->body(implode("\n\n", array_filter([$this->countsLine($importedCount, $failedCount, $service), ...$companyLines, $ignoredLine, $errorSummary])))
            ->actions([$this->failedRowsAction($failedCsvPath)])
            ->sendToDatabase($user);
    }

    /**
     * The counts of the notification: "Imported: N | Failed: M" when the import only creates, and the created and the
     * updated contacts apart in the other modes.
     */
    private function countsLine(int $importedCount, int $failedCount, ContactImportService $service): string
    {
        if ($this->mode === ImportMode::CreateOnly) {
            return "Imported: {$importedCount} | Failed: {$failedCount}";
        }

        $updatedCount = $service->updatedContactsCount();
        $createdCount = $importedCount - $updatedCount;

        return "Created: {$createdCount} | Updated: {$updatedCount} | Failed: {$failedCount}";
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
                $this->countsLine($importedCount, $failedCount, $service).". The file could not be read after row {$lastRowRead}, so the rest of it was not imported. The contacts above were kept; import the remaining rows in a new file.",
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
            ->body($this->mode === ImportMode::CreateOnly
                ? 'Something went wrong while importing your file. Contacts already imported were kept; you can upload the file again, existing contacts will be reported as already existing.'
                : 'Something went wrong while importing your file. Rows already imported or updated were kept; you can upload the file again.')
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
}
