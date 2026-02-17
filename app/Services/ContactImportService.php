<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use DateTime;

class ContactImportService
{
    public function __construct(private readonly int $organizationId) {}

    /**
     * Process a single CSV row.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    public function processRow(array $row, array $customFieldsByName): array
    {
        // handles base attributes
        $name = trim($row['name'] ?? '');
        $email = trim($row['email'] ?? '');

        if (blank($name)) {
            return ['success' => false, 'error' => 'Name is required.'];
        }

        if (blank($email)) {
            return ['success' => false, 'error' => 'Email is required.'];
        }

        // check email uniqueness
        $existingContact = Contact::where('organization_id', $this->organizationId)
            ->where('email', $email)
            ->first();
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

            if (blank($rawValue)) {
                continue;
            }

            $value = $this->castFieldValue($field, $rawValue);

            if ($value === null) {
                return ['success' => false, 'error' => "Invalid value for field '{$fieldName}': {$rawValue}"];
            }

            if ($field->unique) {
                $checkValue = is_array($value) ? json_encode($value) : (string) $value;
                $exists = Contact::where('organization_id', $this->organizationId)
                    ->whereRaw('custom_field_values->>? = ?', [(string) $field->id, $checkValue])
                    ->exists();

                if ($exists) {
                    return ['success' => false, 'error' => "Duplicate value for unique field '{$field->name}'."];
                }
            }

            $customFieldValues[(string) $field->id] = $value;
        }

        $tagIds = $this->resolveTagIds($row['tags'] ?? '');

        $contact = Contact::updateOrCreate(
            ['organization_id' => $this->organizationId, 'email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'custom_field_values' => $customFieldValues,
            ]
        );

        $contact->tags()->sync($tagIds);

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array<int, int>
     */
    public function resolveTagIds(string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $tagNames = array_filter(array_map('trim', explode(';', $raw)));
        $ids = [];

        foreach ($tagNames as $tagName) {
            $tag = Tag::firstOrCreate(
                ['organization_id' => $this->organizationId, 'name' => $tagName]
            );
            $ids[] = $tag->id;
        }

        return $ids;
    }

    private function castFieldValue(CustomField $field, string $raw): mixed
    {
        return match ($field->type) {
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null,
            'url' => filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null,
            'phone' => preg_replace('/[^\d+]/', '', $raw),
            'number' => is_numeric($raw) ? (float) $raw : null,
            'date' => $this->parseDate($raw) ? date('Y-m-d', strtotime($raw)) : null,
            'multiselect' => $this->parseMultiselectValue($field, $raw),
            'select' => $this->parseSelectValue($field, trim($raw)),
            default => trim($raw),
        };
    }

    public function parseDate($raw)
    {
        $raw = trim($raw); // always trim whitespace

        // Try formats in order: most specific first
        $formats = [
            'd-m-Y H:i:s',  // with time
            'd-m-Y',        // date only
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $raw);

            // Check if parsing succeeded AND round-trip matches exactly
            if ($date !== false && $date->format($format) === $raw) {
                // Success! Return the DateTime object (or formatted string)
                return $date;
            }
        }

        // If no format matched perfectly
        return false;
    }

    public function parseMultiselectValue(CustomField $field, string $raw): ?array
    {
        // values are seperated by ;
        $values = $raw ? array_filter(array_map('trim', explode(';', $raw))) : [];

        $allowedValues = collect($field->options ?? [])->pluck('value')->toArray();

        foreach ($values as $v) {
            if (! in_array($v, $allowedValues)) {
                return null;
            }
        }

        return $values;
    }

    public function parseSelectValue(CustomField $field, string $value): ?string
    {
        $allowedValues = collect($field->options ?? [])->pluck('value')->toArray();

        if (! in_array($value, $allowedValues)) {
            return null;
        }

        return $value;
    }
}
