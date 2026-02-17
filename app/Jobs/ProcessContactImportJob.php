<?php

namespace App\Jobs;

use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessContactImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $filePath,
        private readonly int $organizationId,
        private readonly int $userId
    ) {}

    public function handle(): void
    {
        $customFieldsByName = CustomField::where('organization_id', $this->organizationId)
            ->orderBy('order')
            ->get()
            ->keyBy('name')
            ->all();

        $service = new ContactImportService($this->organizationId);

        $content = Storage::disk('local')->get($this->filePath);
        $tmp = tmpfile();
        fwrite($tmp, $content);
        rewind($tmp);
        $tmpPath = stream_get_meta_data($tmp)['uri'];

        $file = new \SplFileObject($tmpPath, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);

        $headers = null;
        $importedCount = 0;
        $failedCount = 0;

        /** @var array<int, array{row: int, data: array<string, string>, error: string}> $failedRows */
        $failedRows = [];
        $rowNumber = 0;

        foreach ($file as $row) {
            /** @var array<int, string>|false $row */
            if ($row === false) {
                continue;
            }

            if ($headers === null) {
                $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
                $headers = array_map('trim', $row);

                continue;
            }

            $rowNumber++;

            $row = array_map('trim', $row);

            if (count(array_filter($row)) === 0) {
                continue;
            }

            if (count($row) !== count($headers)) {
                $failedCount++;
                $failedRows[] = [
                    'row' => $rowNumber,
                    'data' => array_combine($headers, array_pad($row, count($headers), '')),
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

        unset($file);
        fclose($tmp);

        Storage::disk('local')->delete($this->filePath);

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
