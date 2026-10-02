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
     * The contact is created without model events on purpose: segment membership comes from the full sync queued
     * when the import finishes. Any future Contact observer that should apply to imports must be called explicitly.
     *
     * The insert falls back to the existing row on a unique violation, so a contact created by someone else after
     * the existence check above is reported as a failed row instead of crashing the import.
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

            if (blank($rawValue)) {
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

        $contact = Contact::withoutEvents(fn (): Contact => Contact::withTrashed()->createOrFirst(
            ['organization_id' => $this->organizationId, 'email' => $email],
            ['name' => $name, 'phone' => $phone, 'custom_field_values' => $customFieldValues],
        ));

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

        $tagNames = array_filter(array_map('trim', explode(';', $raw)));
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
