<?php

namespace App\Services;

use App\Exceptions\UnreadableImportFileException;
use DateInterval;
use DateTimeInterface;
use Generator;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Turns a CSV or Excel (.xlsx) file into rows of strings, so both formats go through the same import logic.
 *
 * Only the first worksheet of an Excel file is read. The reader does not validate the content. Excel date cells
 * are written as "d-m-Y", the format ContactImportService expects for date custom fields.
 */
class ContactImportFileReader
{
    /**
     * The largest contacts import file, in kilobytes. Livewire's temporary upload limit (`config/livewire.php`) and
     * PHP's `upload_max_filesize` / `post_max_size` (see the ops checklist) must stay above it.
     */
    public const MAX_UPLOAD_KILOBYTES = 10240;

    private const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /**
     * Yields every non-empty row of the first sheet as a list of trimmed strings, header row included.
     *
     * @return Generator<int, array<int, string>>
     *
     * @throws UnreadableImportFileException
     */
    public function rows(string $absolutePath, string $extension): Generator
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            throw UnreadableImportFileException::unsupportedType($extension);
        }

        $reader = null;

        try {
            $reader = SimpleExcelReader::create($absolutePath, $extension === 'xlsx' ? 'xlsx' : 'csv')->noHeaderRow();

            $isFirstRow = true;

            foreach ($reader->getRows() as $row) {
                $cells = array_map($this->normalizeCell(...), array_values($row));

                if ($isFirstRow && $cells !== []) {
                    $cells[0] = ltrim($cells[0], "\xEF\xBB\xBF");
                    $isFirstRow = false;
                }

                if (count(array_filter($cells, fn (string $cell): bool => $cell !== '')) === 0) {
                    continue;
                }

                yield $cells;
            }
        } catch (UnreadableImportFileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableImportFileException::unreadable($exception);
        } finally {
            $reader?->close();
        }
    }

    private function normalizeCell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => trim($value),
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->normalizeFloat($value),
            $value instanceof DateTimeInterface => $value->format('d-m-Y'),
            $value instanceof DateInterval => $value->format('%H:%I:%S'),
            default => trim((string) $value),
        };
    }

    private function normalizeFloat(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
}
