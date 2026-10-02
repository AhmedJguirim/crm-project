<?php

namespace App\Jobs;

use App\Exceptions\UnreadableImportFileException;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Imports the contacts of an uploaded CSV or Excel (.xlsx) file. Only the first worksheet is read.
 *
 * Excel drops empty trailing cells, so shorter Excel rows are padded to the header length. CSV rows must match it.
 *
 * It runs on the `imports` queue, apart from the quick jobs, because a large file takes a long time.
 */
class ProcessContactImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Just under the timeout of the `imports` Horizon supervisor, so a very long import fails cleanly before the
     * worker is killed.
     */
    public int $timeout = 1740;

    public function __construct(
        private readonly string $filePath,
        private readonly int $organizationId,
        private readonly int $userId
    ) {
        $this->onQueue('imports');
    }

    public function handle(ContactImportFileReader $reader): void
    {
        $customFieldsByName = CustomField::where('organization_id', $this->organizationId)
            ->orderBy('order')
            ->get()
            ->keyBy('name')
            ->all();

        $service = new ContactImportService($this->organizationId);

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
        $basePath = tempnam(sys_get_temp_dir(), 'contact-import-');
        $tmpPath = "{$basePath}.{$extension}";

        rename($basePath, $tmpPath);
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
        $allHeaders = array_merge(['_row_number', '_error'], $headers);

        $lines = [implode(',', array_map(fn ($h) => '"'.str_replace('"', '""', $h).'"', $allHeaders))];

        foreach ($failedRows as $failedRow) {
            $cells = [
                '"'.$failedRow['row'].'"',
                '"'.str_replace('"', '""', $failedRow['error']).'"',
            ];

            foreach ($headers as $header) {
                $value = $failedRow['data'][$header] ?? '';
                $cells[] = '"'.str_replace('"', '""', $value).'"';
            }

            $lines[] = implode(',', $cells);
        }

        $content = implode("\n", $lines)."\n";
        $path = 'contact-imports/failed-'.uniqid().'.csv';

        Storage::disk('local')->put($path, $content);

        return $path;
    }
}
