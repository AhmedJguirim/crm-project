<?php

namespace App\Services\Exports;

use Closure;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Writes an export file: a bold header row, then one row per record. The type of the file comes from the extension of
 * the path. Text is always an explicit text cell, so a value starting with `=` is never turned into a formula.
 */
class ExportFileWriter
{
    /**
     * @param  array<int, string>  $headers  Final: the caller applied {@see ExportCells::headers()}.
     * @param  iterable<int, iterable<int, mixed>>  $chunks  The records, a chunk at a time.
     * @param  Closure(mixed): array<int, string|int|float>  $rowOf  The final cells of a record: the caller applied {@see ExportCells::row()}.
     * @return int The number of records written.
     */
    public static function write(string $path, array $headers, iterable $chunks, Closure $rowOf): int
    {
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();

        $headerStyle = (new Style)->setFontBold();

        $writer->addRow(new Row(array_map(
            fn (string $header): Cell => new StringCell($header, $headerStyle),
            $headers,
        )));

        $count = 0;

        foreach ($chunks as $records) {
            foreach ($records as $record) {
                $writer->addRow(new Row(array_map(
                    fn (string|int|float $cell): Cell => is_string($cell) ? new StringCell($cell, null) : Cell::fromValue($cell),
                    $rowOf($record),
                )));
                $count++;
            }
        }

        $writer->close();

        return $count;
    }
}
