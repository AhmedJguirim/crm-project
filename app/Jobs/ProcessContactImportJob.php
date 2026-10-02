<?php

namespace App\Jobs;

use App\Exceptions\UnreadableImportFileException;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use App\Support\TemporaryFile;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
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
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("organization-{$this->organizationId}"))
                ->releaseAfter(30)
                ->expireAfter(1800),
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
        $service = new ContactImportService($this->organizationId);

        $customFieldsByName = $service->customFields()->keyBy('name')->all();

        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        $tmpPath = $this->copyToTemporaryFile($extension);

        $headers = null;
        $importedCount = 0;
        $failedCount = 0;

        /** @var array<int, array{row: int, data: array<string, string>, error: string}> $failedRows */
        $failedRows = [];
        $rowNumber = 0;

        try {
            foreach ($reader->rows($tmpPath, $extension) as $row) {
                if ($headers === null) {
                    $headers = $row;

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
                $result = $service->processRow($rowData, $customFieldsByName);

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
        } catch (UnreadableImportFileException) {
            $this->handleUnreadableFile($importedCount);

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

        if ($failedCount === 0) {
            Notification::make()
                ->success()
                ->title('Import complete')
                ->body("Imported: {$importedCount} | Failed: 0")
                ->sendToDatabase($user);

            return;
        }

        $failedCsvPath = $this->storeFailedRowsCsv($headers, $failedRows);

        $errorSummary = collect($failedRows)
            ->take(5)
            ->map(fn ($r) => "Row {$r['row']}: {$r['error']}")
            ->implode("\n");

        Notification::make()
            ->warning()
            ->title('Import complete with errors')
            ->body("Imported: {$importedCount} | Failed: {$failedCount}\n\n{$errorSummary}")
            ->actions([
                Action::make('downloadFailedRows')
                    ->label('Download failed rows')
                    ->url(route('contacts.import.failed-rows', ['path' => $failedCsvPath]))
                    ->openUrlInNewTab(),
            ])
            ->sendToDatabase($user);
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

    private function handleUnreadableFile(int $importedCount): void
    {
        Storage::disk('local')->delete($this->filePath);

        if ($importedCount > 0) {
            $this->syncPublishedSegments();
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Import failed')
            ->body("We couldn't read this file. Upload a CSV or an Excel (.xlsx) file whose first sheet starts with a header row.")
            ->sendToDatabase($user);
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
     * @param  array<int, string>  $headers
     * @param  array<int, array{row: int, data: array<string, string>, error: string}>  $failedRows
     */
    private function storeFailedRowsCsv(array $headers, array $failedRows): string
    {
        $tmpPath = TemporaryFile::reserve('failed-rows-', 'csv');

        try {
            $writer = SimpleExcelWriter::create($tmpPath)
                ->noHeaderRow()
                ->addRow(['_row_number', '_error', ...$headers]);

            foreach ($failedRows as $failedRow) {
                $writer->addRow([
                    $failedRow['row'],
                    $failedRow['error'],
                    ...array_map(fn (string $header): string => $failedRow['data'][$header] ?? '', $headers),
                ]);
            }

            $writer->close();

            $path = 'contact-imports/failed-'.uniqid().'.csv';

            Storage::disk('local')->put($path, file_get_contents($tmpPath));
        } finally {
            @unlink($tmpPath);
        }

        return $path;
    }
}
