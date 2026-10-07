<?php

namespace App\Services;

use App\Enums\CompanyIndustry;
use App\Models\CompanyCustomField;
use App\Services\Imports\ImportCellParser;
use App\Support\TemporaryFile;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the companies import template: the headers the import expects and one example row that imports unchanged,
 * as an .xlsx file with typed cells (see `ContactImportTemplate`).
 */
class CompanyImportTemplate
{
    private const COLUMN_WIDTH = 22;

    /** The empty rows written below the example, only so that their cells carry the column format. */
    private const FORMATTED_EMPTY_ROWS = 1000;

    /** The built-in columns written as numbers (no text format). */
    private const NUMBER_COLUMNS = ['employees', 'annual revenue'];

    private readonly CompanyImportService $importService;

    private readonly ImportCellParser $parser;

    public function __construct(int $organizationId)
    {
        $this->importService = new CompanyImportService($organizationId);
        $this->parser = new ImportCellParser($organizationId);
    }

    public static function forOrganization(int $organizationId): self
    {
        return new self($organizationId);
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return [
            ...CompanyImportService::COLUMNS,
            ...$this->customFields()->map(fn (CompanyCustomField $field): string => $field->name)->all(),
        ];
    }

    /** @return array<int, string|int|float|DateTimeInterface> */
    public function exampleRow(): array
    {
        return [
            'Acme Corp',
            'acme.com',
            $this->importService->companyTypes()->first()?->name ?? '',
            '+1 555 0100',
            CompanyIndustry::Software->getLabel(),
            50,
            1000000,
            '1 Main St',
            'Springfield',
            '12345',
            'United States',
            'Key account',
            ...$this->customFields()->map(fn (CompanyCustomField $field): string|int|float|DateTimeInterface => $this->exampleCellValue($field))->all(),
        ];
    }

    /**
     * Writes the template as an .xlsx file to a temporary file.
     *
     * @return string The absolute path of the file.
     */
    public function writeXlsx(): string
    {
        $path = TemporaryFile::reserve('companies-template-', 'xlsx');
        $columnCount = count($this->headers());

        $writer = SimpleExcelWriter::create(
            $path,
            'xlsx',
            fn (Writer $writer) => $writer->getOptions()->setColumnWidth(self::COLUMN_WIDTH, ...range(1, $columnCount)),
        )->noHeaderRow();

        $headerStyle = (new Style)->setFontBold()->setFormat('@');

        $writer->addRow(new Row(array_map(
            fn (string $header): Cell => Cell::fromValue($header, $headerStyle),
            $this->headers(),
        )));
        $writer->addRow(new Row($this->cells($this->exampleRow())));

        $emptyRow = new Row($this->formattedEmptyCells($columnCount));

        for ($i = 0; $i < self::FORMATTED_EMPTY_ROWS; $i++) {
            $writer->addRow($emptyRow);
        }

        $writer->close();

        return $path;
    }

    /**
     * The cells of one formatted empty row. OpenSpout skips a row whose cells are all empty, even when they carry a
     * format, so the first cell holds an empty string; the import reads it as blank and skips the row.
     *
     * @return array<int, Cell>
     */
    private function formattedEmptyCells(int $columnCount): array
    {
        $cells = $this->cells(array_fill(0, $columnCount, null));
        $cells[0] = new StringCell('', $cells[0]->getStyle());

        return $cells;
    }

    /**
     * Converts the example string of a custom field to the value of its column kind.
     */
    private function exampleCellValue(CompanyCustomField $field): string|int|float|DateTimeInterface
    {
        $example = $this->parser->exampleValueFor($field);

        return match ($field->type) {
            'number' => $example + 0,
            'date' => $this->dateCellValue($example),
            default => $example,
        };
    }

    private function dateCellValue(string $example): string|DateTimeInterface
    {
        $date = $this->parser->parseDate($example);

        return $date === false ? $example : DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }

    /**
     * @param  array<int, string|int|float|DateTimeInterface|null>  $values  One value per column, in the order of the headers.
     * @return array<int, Cell>
     */
    private function cells(array $values): array
    {
        $styles = $this->columnStyles();

        return array_map(
            fn (string|int|float|DateTimeInterface|null $value, int $index): Cell => Cell::fromValue($value, $styles[$index]),
            $values,
            array_keys($values),
        );
    }

    /**
     * The style of each column, aligned with `headers()`: Text for text columns, `dd-mm-yyyy` for dates and none (General)
     * for numbers.
     *
     * @return array<int, Style|null>
     */
    private function columnStyles(): array
    {
        $text = (new Style)->setFormat('@');
        $date = (new Style)->setFormat('dd-mm-yyyy');

        return [
            ...array_map(
                fn (string $column): ?Style => in_array($column, self::NUMBER_COLUMNS, true) ? null : $text,
                CompanyImportService::COLUMNS,
            ),
            ...$this->customFields()->map(fn (CompanyCustomField $field): ?Style => match ($field->type) {
                'number' => null,
                'date' => $date,
                default => $text,
            })->all(),
        ];
    }

    /** @return Collection<int, CompanyCustomField> */
    private function customFields(): Collection
    {
        return $this->importService->customFields();
    }
}
