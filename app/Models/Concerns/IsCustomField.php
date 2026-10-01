<?php

namespace App\Models\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shared behaviour of custom field definitions (contact and company custom fields).
 *
 * Every definition gets an immutable `key`, generated randomly on creation (unrelated to its name) and unique
 * within the scope returned by keyUniquenessScope(). Values are stored under that key in `custom_field_values`,
 * so renaming a field never orphans the values already saved for it.
 */
trait IsCustomField
{
    public static function bootIsCustomField(): void
    {
        static::creating(function (Model $field): void {
            $field->organization_id ??= Filament::getTenant()?->getKey();

            if (blank($field->key)) {
                $field->key = $field->generateUniqueKey();
            }
        });

        static::updating(function (Model $field): void {
            if ($field->isDirty('key')) {
                $field->key = $field->getOriginal('key');
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
