<?php

namespace App\Services;

use App\Exceptions\DuplicateCustomFieldValueException;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Support\CsvDialect;
use DateTime;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ContactImportService
{
    /** Separates several tags, or several values of a multi-select field, in one cell. */
    public const MULTI_VALUE_SEPARATOR = ';';

    /** The columns of an import file that are not custom fields. */
    public const BASE_COLUMNS = ['name', 'email', 'phone', 'tags'];

    /** The columns the failed rows file adds in front of the original ones, so a corrected file can be imported again. */
    public const FAILED_ROWS_META_COLUMNS = ['_row_number', '_error'];

    /** The date format shown to users; ISO (`Y-m-d`) and a trailing time are accepted too. */
    public const DATE_FORMAT = 'd-m-Y';

    /**
     * @param  CsvDialect|null  $dialect  How the file is written. A file with `;` between columns is a European Excel
     *                                    file, so its numbers may have a decimal comma and its dates may be d/m/Y or d.m.Y.
     */
    public function __construct(
        private readonly int $organizationId,
        private readonly ?CsvDialect $dialect = null,
    ) {}

    /**
     * The custom fields an import of the organization fills, in column order. The import template uses the same
     * list, so its columns are the ones the import matches.
     *
     * @return Collection<int, CustomField>
     */
    public function customFields(): Collection
    {
        return CustomField::where('organization_id', $this->organizationId)
            ->orderBy('order')
            ->get();
    }

    /**
     * The headers of a file that no field uses, as the notification names them. A header matches the base columns, the
     * name of an active custom field exactly, or a column of the failed rows file; one that matches the name of a
     * deleted custom field says so.
     *
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    public function ignoredColumns(array $headers): array
    {
        $known = [
            ...self::BASE_COLUMNS,
            ...self::FAILED_ROWS_META_COLUMNS,
            ...$this->customFields()->pluck('name')->all(),
        ];

        $ignored = array_values(array_unique(array_filter(
            $headers,
            fn (string $header): bool => $header !== '' && ! in_array($header, $known, true),
        )));

        if ($ignored === []) {
            return [];
        }

        $deleted = CustomField::forOrganization($this->organizationId)->onlyTrashed()->pluck('name')->all();

        return array_map(
            fn (string $header): string => in_array($header, $deleted, true) ? "\"{$header}\" (deleted field)" : "\"{$header}\"",
            $ignored,
        );
    }

    /**
     * Process a single CSV row.
     *
     * The contact is created without model events on purpose: segment membership comes from the full sync queued
     * when the import finishes. Any future Contact observer that should apply to imports must be called explicitly.
     *
     * The insert falls back to the existing row on a unique violation, so a contact created by someone else after
     * the existence check above is reported as a failed row instead of crashing the import. The value of a unique
     * custom field taken after the check above is reported the same way (see `EnforcesUniqueCustomFieldValues`).
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    public function processRow(array $row, array $customFieldsByName): array
    {
        // handles base attributes
        $name = trim($row['name'] ?? '');
        $email = mb_strtolower(trim($row['email'] ?? ''));

        if (blank($name)) {
            return ['success' => false, 'error' => 'Name is required.'];
        }

        if (blank($email)) {
            return ['success' => false, 'error' => 'Email is required.'];
        }

        // check email uniqueness
        $existingContact = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->where('email', $email)
            ->first();

        if ($existingContact?->trashed()) {
            return ['success' => false, 'error' => "A deleted contact with email '{$email}' already exists. Restore it from the trash instead of importing it again."];
        }

        if ($existingContact) {
            return ['success' => false, 'error' => "A contact with email '{$email}' already exists."];
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => "Invalid email: {$email}"];
        }

        $phone = trim($row['phone'] ?? '') ?: null;

        // handle custom fields
        $customFieldValues = [];
        foreach ($customFieldsByName as $fieldName => $field) {
            // skip if the column doesn't exist in the CSV
            $rawValue = $row[$fieldName] ?? null;

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->castFieldValue($field, $rawValue);

            if ($value === null) {
                return ['success' => false, 'error' => "Invalid value for field '{$fieldName}': {$rawValue}"];
            }

            if ($field->unique) {
                $exists = Contact::query()
                    ->where('organization_id', $this->organizationId)
                    ->whereCustomFieldValue($field->key, $value)
                    ->exists();

                if ($exists) {
                    return ['success' => false, 'error' => "Duplicate value for unique field '{$field->name}'."];
                }
            }

            $customFieldValues[$field->key] = $value;
        }

        $tagIds = $this->resolveTagIds($row['tags'] ?? '');

        try {
            $contact = Contact::withoutEvents(fn (): Contact => Contact::withTrashed()->createOrFirst(
                ['organization_id' => $this->organizationId, 'email' => $email],
                ['name' => $name, 'phone' => $phone, 'custom_field_values' => $customFieldValues],
            ));
        } catch (DuplicateCustomFieldValueException $exception) {
            return ['success' => false, 'error' => "Duplicate value for unique field '{$exception->fieldName}'."];
        }

        if (! $contact->wasRecentlyCreated) {
            return ['success' => false, 'error' => "A contact with email '{$email}' already exists."];
        }

        $contact->tags()->sync($tagIds);

        return ['success' => true, 'error' => null];
    }

    /**
     * Resolve the tag names of an imported row, creating missing tags and restoring trashed ones. `firstOrCreate()`
     * already falls back to the existing row when someone else creates the tag in the meantime.
     *
     * @return array<int, int>
     */
    public function resolveTagIds(string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $tagNames = array_filter(array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw)));
        $ids = [];

        foreach ($tagNames as $tagName) {
            $tag = Tag::withTrashed()->firstOrCreate(
                ['organization_id' => $this->organizationId, 'name' => $tagName]
            );

            if ($tag->trashed()) {
                $tag->restore();
            }

            $ids[] = $tag->id;
        }

        return $ids;
    }

    private function castFieldValue(CustomField $field, string $raw): mixed
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

    private function parseNumber(string $raw): ?float
    {
        if ($this->dialect?->isEuropean() && preg_match('/^-?\d+,\d+$/', $raw) === 1) {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function formatDate(string $raw): ?string
    {
        $date = $this->parseDate($raw);

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function parsePhone(string $raw): ?string
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
    public function parseMultiselectValue(CustomField $field, string $raw): ?array
    {
        $values = $this->splitMultiValue($raw);

        if ($values === []) {
            return null;
        }

        $allowedValues = collect($field->options ?? [])->pluck('value')->toArray();

        foreach ($values as $v) {
            if (! in_array($v, $allowedValues)) {
                return null;
            }
        }

        return $values;
    }

    /**
     * The non-empty, trimmed parts of a multi-value cell, as a list (a stored JSON array, never an object).
     *
     * @return array<int, string>
     */
    private function splitMultiValue(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw)),
            fn (string $value): bool => $value !== '',
        ));
    }

    public function parseSelectValue(CustomField $field, string $value): ?string
    {
        $allowedValues = collect($field->options ?? [])->pluck('value')->toArray();

        if (! in_array($value, $allowedValues)) {
            return null;
        }

        return $value;
    }

    /**
     * A value for the import template that the import accepts for the field, as it casts it.
     */
    public function exampleValueFor(CustomField $field): string
    {
        $optionValues = collect($field->options ?? [])->pluck('value')->map(fn ($value): string => (string) $value);

        return match ($field->type) {
            'textarea' => 'Some notes',
            'email' => 'jane@example.com',
            'url' => 'https://example.com',
            'phone' => '+33612345678',
            'number' => '42',
            'date' => now()->startOfYear()->addDays(14)->format(self::DATE_FORMAT),
            'select' => $optionValues->first() ?? '',
            'multiselect' => $optionValues
                ->reject(fn (string $value): bool => str_contains($value, self::MULTI_VALUE_SEPARATOR))
                ->take(2)
                ->implode(self::MULTI_VALUE_SEPARATOR),
            default => 'Some text',
        };
    }
}
