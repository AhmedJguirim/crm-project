<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use DateTime;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Writes contacts to a spreadsheet with the column names the contacts import reads, so the file can be imported again.
 *
 * The values are the ones a person reads: labels for the enums and the options of select fields, dates as dd-mm-yyyy.
 * In a CSV every cell is text; in an .xlsx the number custom fields are numeric cells and everything else is an
 * explicit text cell, so a value starting with `=` is never turned into a formula.
 *
 * A spreadsheet app evaluates a CSV cell that starts with `=`, `@`, a tab or a carriage return, or with `+` or `-`
 * followed by anything but digits and number punctuation. Those cells get a leading `'`, header cells included;
 * phone numbers (`+33 1`) and negative numbers are left as stored. Importing such a cell again gives the value with
 * the leading `'`.
 */
class ContactExportWriter
{
    /** The columns that are not custom fields. `all companies` is for people; the import ignores it. */
    public const COLUMNS = ['name', 'email', 'phone', 'status', 'lead source', 'tags', 'company', 'company website', 'all companies'];

    /**
     * @param  Collection<int, CustomField>  $customFields  The active custom fields, in column order.
     */
    public function __construct(private readonly Collection $customFields) {}

    /** @return array<int, string> */
    public function headers(ExportFormat $format = ExportFormat::Xlsx): array
    {
        $headers = [
            ...self::COLUMNS,
            ...$this->customFields->map(fn (CustomField $field): string => $field->name)->all(),
        ];

        return $format === ExportFormat::Csv ? array_map(self::csvSafe(...), $headers) : $headers;
    }

    /**
     * The text a CSV cell gets: the value, with a leading `'` when a spreadsheet app would evaluate it as a formula.
     */
    public static function csvSafe(string $value): string
    {
        $first = $value[0] ?? '';

        if (in_array($first, ["\t", "\r", "\n"], true)) {
            return "'".$value;
        }

        $trimmed = ltrim($value, ' ');
        $lead = $trimmed[0] ?? '';

        if ($lead === '=' || $lead === '@') {
            return "'".$value;
        }

        if (($lead === '+' || $lead === '-') && preg_match('/^[+-][\d\s().-]+$/', $trimmed) !== 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * The cells of one contact, in the order of `headers()`. The contact needs its `tags` and `companies` loaded.
     *
     * @return array<int, string|int|float>
     */
    public function row(Contact $contact, ExportFormat $format): array
    {
        $companies = $contact->companies
            ->sortBy(fn (Company $company): string => mb_strtolower($company->name))
            ->values();

        $cells = [
            (string) $contact->name,
            (string) $contact->email,
            (string) $contact->phone,
            (string) $contact->status?->getLabel(),
            (string) $contact->lead_source?->getLabel(),
            $contact->tags->pluck('name')->sortBy(fn (string $name): string => mb_strtolower($name))->implode(';'),
            (string) $companies->first()?->name,
            (string) $companies->first()?->website,
            $companies->count() >= 2 ? $companies->pluck('name')->implode('; ') : '',
            ...$this->customFields
                ->map(fn (CustomField $field): string|int|float => $this->customFieldCell($contact, $field, $format))
                ->all(),
        ];

        return $format === ExportFormat::Csv
            ? array_map(fn (string|int|float $cell): string|int|float => is_string($cell) ? self::csvSafe($cell) : $cell, $cells)
            : $cells;
    }

    /**
     * Writes the header and the contacts of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Contact>>  $chunks  Contacts with their `tags` and `companies` loaded.
     * @return int The number of contacts written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();

        $headerStyle = (new Style)->setFontBold();

        $writer->addRow(new Row(array_map(
            fn (string $header): Cell => new StringCell($header, $headerStyle),
            $this->headers($format),
        )));

        $count = 0;

        foreach ($chunks as $contacts) {
            foreach ($contacts as $contact) {
                $writer->addRow(new Row(array_map(
                    fn (string|int|float $cell): Cell => is_string($cell) ? new StringCell($cell, null) : Cell::fromValue($cell),
                    $this->row($contact, $format),
                )));
                $count++;
            }
        }

        $writer->close();

        return $count;
    }

    private function customFieldCell(Contact $contact, CustomField $field, ExportFormat $format): string|int|float
    {
        $value = $contact->customFieldValue($field->key);

        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        return match ($field->type) {
            'select' => $this->optionLabel($field, $value),
            'multiselect' => collect((array) $value)->map(fn (mixed $part): string => $this->optionLabel($field, $part))->implode(ContactImportService::MULTI_VALUE_SEPARATOR),
            'date' => $this->dateText($value),
            'number' => $this->numberCell($value, $format),
            default => is_array($value) ? implode(ContactImportService::MULTI_VALUE_SEPARATOR, array_map('strval', $value)) : (string) $value,
        };
    }

    /**
     * The label of the option with the stored value, or the stored value when it is no longer an option.
     */
    private function optionLabel(CustomField $field, mixed $value): string
    {
        $stored = is_scalar($value) ? (string) $value : '';

        $option = collect($field->options ?? [])->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $stored);

        return (string) ($option['label'] ?? $stored);
    }

    private function dateText(mixed $value): string
    {
        $stored = (string) $value;
        $date = DateTime::createFromFormat('Y-m-d', $stored);

        return $date !== false && $date->format('Y-m-d') === $stored
            ? $date->format(ContactImportService::DATE_FORMAT)
            : $stored;
    }

    /**
     * A decimal point, no thousands separator, and no `.0` on an integer. A numeric cell in an .xlsx.
     */
    private function numberCell(mixed $value, ExportFormat $format): string|int|float
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        $number = $value + 0;

        if ($format === ExportFormat::Xlsx) {
            return $number;
        }

        if (is_int($number) || ($number == (int) $number && abs($number) < 1e15)) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format((float) $number, 10, '.', ''), '0'), '.');
    }
}
