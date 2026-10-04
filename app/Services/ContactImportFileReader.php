<?php

namespace App\Services;

use App\Exceptions\UnreadableImportFileException;
use App\Support\CsvDialect;
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
     * The delimiter and the encoding of a CSV file, read from its bytes. Other files get the default dialect, and so
     * does a file that cannot be read: reading the rows is what reports that.
     *
     * The delimiter is `;` only when the header line holds strictly more `;` than `,` outside double quotes, so a tie
     * stays a comma file. The encoding is UTF-8 when the file starts with a UTF-8 byte order mark or is valid UTF-8
     * from start to end, and Windows-1252 otherwise, which is what Excel writes for "CSV (separator: semicolon)".
     */
    public function dialect(string $absolutePath, string $extension): CsvDialect
    {
        if (! in_array(strtolower($extension), ['csv', 'txt'], true)) {
            return new CsvDialect;
        }

        $contents = @file_get_contents($absolutePath);

        if ($contents === false || $contents === '') {
            return new CsvDialect;
        }

        $hasByteOrderMark = str_starts_with($contents, "\xEF\xBB\xBF");

        return new CsvDialect(
            delimiter: $this->delimiterOf($hasByteOrderMark ? substr($contents, 3) : $contents),
            encoding: $hasByteOrderMark || mb_check_encoding($contents, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        );
    }

    /**
     * Counts the `;` and the `,` of the first line, leaving out what is between double quotes (a doubled quote closes
     * and opens again, so it leaves the count unchanged). A line break between quotes does not end the line.
     */
    private function delimiterOf(string $contents): string
    {
        $semicolons = 0;
        $commas = 0;
        $inQuotes = false;

        for ($position = 0, $length = strlen($contents); $position < $length; $position++) {
            $character = $contents[$position];

            if ($character === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if ($inQuotes) {
                continue;
            }

            if ($character === "\n" || $character === "\r") {
                break;
            }

            $semicolons += $character === ';' ? 1 : 0;
            $commas += $character === ',' ? 1 : 0;
        }

        return $semicolons > $commas ? ';' : ',';
    }

    /**
     * Yields every non-empty row of the first sheet as a list of trimmed strings, header row included.
     *
     *
     * @param  CsvDialect|null  $dialect  The dialect of a CSV file, read from the file when not given.
     * @return Generator<int, array<int, string>>
     *
     * @throws UnreadableImportFileException
     */
    public function rows(string $absolutePath, string $extension, ?CsvDialect $dialect = null): Generator
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            throw UnreadableImportFileException::unsupportedType($extension);
        }

        $reader = null;

        try {
            $reader = SimpleExcelReader::create($absolutePath, $extension === 'xlsx' ? 'xlsx' : 'csv')->noHeaderRow();

            if ($extension !== 'xlsx') {
                $dialect ??= $this->dialect($absolutePath, $extension);
                $reader->useDelimiter($dialect->delimiter)->useEncoding($dialect->encoding);
            }

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
