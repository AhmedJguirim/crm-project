<?php

namespace App\Services\Imports;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use App\Support\CsvDialect;
use BackedEnum;
use DateTime;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;

/**
 * Turns the text of an imported cell into the value stored in the database, or null (false for a date) when the cell
 * is not valid. Shared by every import.
 */
class ImportCellParser
{
    /** Separates several tags, or several values of a multi-select field, in one cell. */
    public const MULTI_VALUE_SEPARATOR = ';';

    /** The date format shown to users; ISO (`Y-m-d`) and a trailing time are accepted too. */
    public const DATE_FORMAT = 'd-m-Y';

    private const MAX_EMPLOYEES = 2147483647;

    private const MAX_ANNUAL_REVENUE = 9999999999999.99;

    /**
     * @param  CsvDialect|null  $dialect  How the file is written. A file with `;` between columns is a European Excel
     *                                    file, so its numbers may have a decimal comma and its dates may be d/m/Y or d.m.Y.
     */
    public function __construct(
        private readonly int $organizationId,
        private readonly ?CsvDialect $dialect = null,
    ) {}

    public function castFieldValue(CustomField|CompanyCustomField $field, string $raw): mixed
    {
        return match ($field->type) {
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null,
            'url' => filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null,
            'phone' => $this->parsePhone($raw),
            'number' => $this->parseNumber($raw),
            'date' => $this->formatDate($raw),
            'multiselect' => $this->parseMultiselectValue($field, $raw),
            'select' => $this->parseSelectValue($field, trim($raw)),
            default => trim($raw),
        };
    }

    public function parseNumber(string $raw): ?float
    {
        if ($this->dialect?->isEuropean() && preg_match('/^-?\d+,\d+$/', $raw) === 1) {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    public function formatDate(string $raw): ?string
    {
        $date = $this->parseDate($raw);

        return $date === false ? null : $date->format('Y-m-d');
    }

    public function parsePhone(string $raw): ?string
    {
        $phone = preg_replace('/[^\d+]/', '', $raw);

        return preg_match('/\d/', $phone) === 1 ? $phone : null;
    }

    /**
     * Accepts "d-m-Y H:i:s", "d-m-Y" and ISO "Y-m-d", and "d/m/Y" and "d.m.Y" in a European Excel file ("03/04/2026" is
     * ambiguous in other files, so it stays refused there). Each format must round-trip exactly, which is what rejects
     * impossible dates such as "31-02-2024".
     */
    public function parseDate(string $raw): DateTimeInterface|false
    {
        $raw = trim($raw);

        $formats = ['d-m-Y H:i:s', self::DATE_FORMAT, 'Y-m-d', ...($this->dialect?->isEuropean() ? ['d/m/Y', 'd.m.Y'] : [])];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $raw);

            if ($date !== false && $date->format($format) === $raw) {
                return $date;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>|null A list of allowed values, or null when a value is not allowed or there is none.
     */
    public function parseMultiselectValue(CustomField|CompanyCustomField $field, string $raw): ?array
    {
        $parts = $this->splitMultiValue($raw);

        if ($parts === []) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->optionValueFor($field, $part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    /**
     * The non-empty, trimmed parts of a multi-value cell, as a list (a stored JSON array, never an object).
     *
     * @return array<int, string>
     */
    public function splitMultiValue(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw)),
            fn (string $value): bool => $value !== '',
        ));
    }

    public function parseSelectValue(CustomField|CompanyCustomField $field, string $value): ?string
    {
        return $this->optionValueFor($field, $value);
    }

    /**
     * The stored value of the option a cell part designates: first an exact stored value (old files and failed-rows
     * files keep working), otherwise the option with that label, ignoring case and surrounding spaces.
     */
    private function optionValueFor(CustomField|CompanyCustomField $field, string $part): ?string
    {
        $options = collect($field->options ?? []);
        $part = trim($part);

        $byValue = $options->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $part);

        if ($byValue !== null) {
            return (string) $byValue['value'];
        }

        $byLabel = $options->first(fn (array $option): bool => mb_strtolower(trim((string) ($option['label'] ?? ''))) === mb_strtolower($part));

        return $byLabel === null ? null : (string) $byLabel['value'];
    }

    /**
     * The case of an enum a cell designates: first its stored value (`active_client`), then its label ignoring case and
     * surrounding spaces (`Active Client`).
     *
     * @param  class-string<BackedEnum&HasLabel>  $enumClass
     */
    public function enumFromCell(string $enumClass, string $raw): ?BackedEnum
    {
        $raw = trim($raw);

        $byValue = $enumClass::tryFrom($raw);

        if ($byValue !== null) {
            return $byValue;
        }

        foreach ($enumClass::cases() as $case) {
            if (mb_strtolower(trim((string) $case->getLabel())) === mb_strtolower($raw)) {
                return $case;
            }
        }

        return null;
    }

    public function parseEmployees(string $raw): ?int
    {
        if (preg_match('/^\d{1,10}$/', $raw) !== 1 || (int) $raw > self::MAX_EMPLOYEES) {
            return null;
        }

        return (int) $raw;
    }

    public function parseAnnualRevenue(string $raw): ?string
    {
        $number = $this->parseNumber($raw);

        if ($number === null || $number < 0 || $number > self::MAX_ANNUAL_REVENUE) {
            return null;
        }

        return number_format($number, 2, '.', '');
    }

    /**
     * A value for the import template that the import accepts for the field, as it casts it.
     */
    public function exampleValueFor(CustomField|CompanyCustomField $field): string
    {
        $optionLabels = collect($field->options ?? [])->pluck('label')->map(fn ($label): string => (string) $label);

        return match ($field->type) {
            'textarea' => 'Some notes',
            'email' => 'jane@example.com',
            'url' => 'https://example.com',
            'phone' => '+33612345678',
            'number' => '42',
            'date' => now()->startOfYear()->addDays(14)->format(self::DATE_FORMAT),
            'select' => $optionLabels->first() ?? '',
            'multiselect' => $optionLabels
                ->reject(fn (string $label): bool => str_contains($label, self::MULTI_VALUE_SEPARATOR))
                ->take(2)
                ->implode(self::MULTI_VALUE_SEPARATOR),
            default => 'Some text',
        };
    }
}
