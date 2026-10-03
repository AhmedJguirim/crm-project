<?php

namespace App\Models\Concerns;

use App\Exceptions\DuplicateCustomFieldValueException;
use App\Models\CompanyCustomField;
use App\Models\CustomField;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the "unique" custom fields when the record is saved, for models using `HasCustomFieldValues`.
 *
 * The form rule and the import's pre-check only look before saving, so two saves at the same moment could both pass
 * them. Here every save that sets the value of a unique field first takes a short PostgreSQL advisory lock for that
 * field and value, checks again inside it, and throws `DuplicateCustomFieldValueException` for a duplicate. The lock
 * lasts until the transaction of the save ends.
 *
 * It overrides `save()` and is not a model event on purpose: imports write with `withoutEvents()`, which would skip an
 * event. A save that sets no unique value runs unchanged, with no transaction and no query.
 *
 * Only the Filament pages and the import catch the exception. Any other code that saves these models from a request
 * would answer a race with a 500; that is accepted, as the form rule catches the common case before the save.
 */
trait EnforcesUniqueCustomFieldValues
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $guarded = $this->dirtyUniqueCustomFieldValues();

        if ($guarded === []) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($guarded, $options): bool {
            foreach (array_keys($guarded) as $lockKey) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$lockKey]);
            }

            foreach ($guarded as [$field, $value]) {
                if ($this->customFieldValueIsTaken($field->key, $value)) {
                    throw new DuplicateCustomFieldValueException($field->name, $field->key);
                }
            }

            return parent::save($options);
        });
    }

    /**
     * The unique fields whose non-blank value is new or changed, by lock key, in the order the locks are taken. A fixed
     * order keeps two saves that set several unique fields from waiting on each other.
     *
     * @return array<string, array{0: CustomField|CompanyCustomField, 1: mixed}>
     */
    private function dirtyUniqueCustomFieldValues(): array
    {
        $values = $this->custom_field_values ?? [];

        if ($values === [] || ($this->exists && ! $this->isDirty('custom_field_values'))) {
            return [];
        }

        $this->organization_id ??= app(TenantContext::class)->id();

        $original = $this->exists ? ($this->getOriginal('custom_field_values') ?? []) : [];
        $guarded = [];

        foreach ($this->customFieldDefinitions()->where('unique', true) as $field) {
            $value = $values[$field->key] ?? null;

            if (blank($value)) {
                continue;
            }

            if (array_key_exists($field->key, $original) && $this->normalizedCustomFieldValue($original[$field->key]) === $this->normalizedCustomFieldValue($value)) {
                continue;
            }

            $lockKey = implode('|', [$this->getTable(), $this->organization_id, $field->key, $this->normalizedCustomFieldValue($value)]);
            $guarded[$lockKey] = [$field, $value];
        }

        ksort($guarded);

        return $guarded;
    }

    /**
     * The value as `scopeWhereCustomFieldValue()` compares it: lists as a whole, everything else as text.
     */
    private function normalizedCustomFieldValue(mixed $value): string
    {
        return is_array($value) ? json_encode(array_values($value)) : (string) $value;
    }

    private function customFieldValueIsTaken(string $key, mixed $value): bool
    {
        return static::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $this->organization_id)
            ->whereCustomFieldValue($key, $value)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();
    }
}
