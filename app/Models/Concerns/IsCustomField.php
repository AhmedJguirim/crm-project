<?php

namespace App\Models\Concerns;

use App\Exceptions\CustomFieldTypeLockedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shared behaviour of custom field definitions (contact and company custom fields).
 *
 * Every definition gets an immutable `key`, generated randomly on creation (unrelated to its name) and unique
 * within the scope returned by keyUniquenessScope(). Values are stored under that key in `custom_field_values`,
 * so renaming a field never orphans the values already saved for it. The `type` is locked once the field exists:
 * stored values are not converted, so changing it would lose them.
 */
trait IsCustomField
{
    public static function bootIsCustomField(): void
    {
        static::saving(function (Model $field): void {
            $field->generateOptionValues();
        });

        static::creating(function (Model $field): void {
            $field->organization_id ??= app(TenantContext::class)->id();

            if (blank($field->key)) {
                $field->key = $field->generateUniqueKey();
            }
        });

        static::updating(function (Model $field): void {
            if ($field->isDirty('key')) {
                $field->key = $field->getOriginal('key');
            }

            if ($field->isDirty('type')) {
                throw CustomFieldTypeLockedException::for($field);
            }
        });
    }

    /**
     * Columns (and their values) within which the key must be unique.
     *
     * @return array<string, mixed>
     */
    abstract public function keyUniquenessScope(): array;

    public function generateUniqueKey(): string
    {
        do {
            $key = static::newRandomKey();
        } while ($this->keyIsTaken($key));

        return $key;
    }

    /**
     * Options are defined by their label: the stored value of an option is generated once, when the option is created,
     * and never changes, so renaming a label is always safe. On creation, given values are kept (code and factories may
     * pass explicit ones). On update, an option whose value is blank, repeated or not among the original values gets a
     * new one, so a value typed or forged in a request can't point at the records of another option.
     */
    public function generateOptionValues(): void
    {
        if (! in_array($this->type, ['select', 'multiselect'], true) || ! is_array($this->options)) {
            return;
        }

        $original = $this->exists ? collect($this->getOriginal('options') ?? [])->pluck('value')->filter()->map(fn (mixed $value): string => (string) $value)->all() : null;
        $taken = [];

        $options = array_map(function (mixed $option) use ($original, &$taken): mixed {
            if (! is_array($option)) {
                return $option;
            }

            $value = filled($option['value'] ?? null) ? (string) $option['value'] : null;
            $isNew = $value === null || in_array($value, $taken, true) || ($original !== null && ! in_array($value, $original, true));

            $option['value'] = $isNew ? static::newOptionValue($taken) : $value;
            $taken[] = $option['value'];

            return $option;
        }, $this->options);

        $this->options = array_values($options);
    }

    /**
     * An opaque identifier for a new option, different from the given ones.
     *
     * @param  array<int, string>  $taken
     */
    public static function newOptionValue(array $taken = []): string
    {
        do {
            $value = 'opt_'.Str::lower(Str::random(10));
        } while (in_array($value, $taken, true));

        return $value;
    }

    public static function newRandomKey(): string
    {
        return 'cf_'.Str::lower(Str::random(12));
    }

    protected function keyIsTaken(string $key): bool
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where($this->keyUniquenessScope())
            ->where('key', $key)
            ->exists();
    }

    /** @return array<string, string> */
    public function optionLabels(): array
    {
        return collect($this->options ?? [])->pluck('label', 'value')->all();
    }

    public function formatValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $labels = $this->optionLabels();

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => $labels[$item] ?? (string) $item)
                ->join(', ');
        }

        if ($this->type === 'select') {
            return $labels[$value] ?? (string) $value;
        }

        return (string) $value;
    }
}
