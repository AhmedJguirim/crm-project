<?php

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Models\CompanyCustomField;
use App\Models\CustomField;
use App\Services\ContactImportService;
use DateTime;
use Illuminate\Database\Eloquent\Model;

/**
 * The cell values the exports share: the CSV formula guard and the custom field values as a person reads them.
 *
 * A spreadsheet app evaluates a CSV cell that starts with `=`, `@`, a tab or a carriage return, or with `+` or `-`
 * followed by anything but digits and number punctuation. Those cells get a leading `'`, header cells included.
 */
class ExportCells
{
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
     * The header cells of the file: guarded for a CSV, unchanged for an .xlsx.
     *
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    public static function headers(array $headers, ExportFormat $format): array
    {
        return $format === ExportFormat::Csv ? array_map(self::csvSafe(...), $headers) : $headers;
    }

    /**
     * The cells of one row: in a CSV the text cells are guarded and the numbers stay numbers, an .xlsx is unchanged.
     *
     * @param  array<int, string|int|float>  $cells
     * @return array<int, string|int|float>
     */
    public static function row(array $cells, ExportFormat $format): array
    {
        return $format === ExportFormat::Csv
            ? array_map(fn (string|int|float $cell): string|int|float => is_string($cell) ? self::csvSafe($cell) : $cell, $cells)
            : $cells;
    }

    /**
     * The value of a custom field of a contact or a company, as a person reads it.
     *
     * @param  Model  $record  A model with the `HasCustomFieldValues` trait.
     */
    public static function customField(Model $record, CustomField|CompanyCustomField $field, ExportFormat $format): string|int|float
    {
        $value = $record->customFieldValue($field->key);

        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        return match ($field->type) {
            'select' => self::optionLabel($field, $value),
            'multiselect' => collect((array) $value)->map(fn (mixed $part): string => self::optionLabel($field, $part))->implode(ContactImportService::MULTI_VALUE_SEPARATOR),
            'date' => self::dateText($value),
            'number' => self::number($value, $format),
            default => is_array($value) ? implode(ContactImportService::MULTI_VALUE_SEPARATOR, array_map('strval', $value)) : (string) $value,
        };
    }

    /**
     * A decimal point, no thousands separator, and no `.0` on an integer. A numeric cell in an .xlsx.
     */
    public static function number(mixed $value, ExportFormat $format): string|int|float
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

    /**
     * The label of the option with the stored value, or the stored value when it is no longer an option.
     */
    private static function optionLabel(CustomField|CompanyCustomField $field, mixed $value): string
    {
        $stored = is_scalar($value) ? (string) $value : '';

        $option = collect($field->options ?? [])->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $stored);

        return (string) ($option['label'] ?? $stored);
    }

    private static function dateText(mixed $value): string
    {
        $stored = (string) $value;
        $date = DateTime::createFromFormat('Y-m-d', $stored);

        return $date !== false && $date->format('Y-m-d') === $stored
            ? $date->format(ContactImportService::DATE_FORMAT)
            : $stored;
    }
}
