<?php

namespace App\Filament\Support\CustomFields;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Renders and validates the custom field values of a record (contact, company, ...).
 *
 * Values are read from and written to the record's `custom_field_values` attribute,
 * keyed by each field's immutable key. Stored values of fields that are not rendered
 * (for example fields of another company type) are kept untouched on save.
 */
class CustomFieldValuesSection
{
    /**
     * @param  Closure(Get): Collection<int, CustomField|CompanyCustomField>  $resolveFields
     * @param  class-string<Model>  $valuesModel  model whose stored values are checked for unique fields
     */
    public static function make(
        Closure $resolveFields,
        string $valuesModel,
        string $heading = 'Additional Information',
    ): Section {
        return Section::make($heading)
            ->statePath('custom_field_values')
            ->collapsible()
            ->columnSpanFull()
            ->schema(fn (Get $get): array => static::components($resolveFields($get), $valuesModel))
            ->hidden(fn (Get $get): bool => $resolveFields($get)->isEmpty())
            ->dehydrateStateUsing(fn (?array $state, ?Model $record): array => static::mergeWithStoredValues($state, $record));
    }

    /**
     * @param  Collection<int, CustomField|CompanyCustomField>  $fields
     * @param  class-string<Model>  $valuesModel
     * @return array<int, Field>
     */
    public static function components(Collection $fields, string $valuesModel): array
    {
        return $fields
            ->map(fn (CustomField|CompanyCustomField $field): Field => static::component($field, $valuesModel))
            ->all();
    }

    /** @param  class-string<Model>  $valuesModel */
    public static function component(CustomField|CompanyCustomField $field, string $valuesModel): Field
    {
        $component = match ($field->type) {
            'email' => TextInput::make($field->key)->email()->maxLength(255),
            'url' => TextInput::make($field->key)->url()->maxLength(255),
            'phone' => TextInput::make($field->key)->tel()->maxLength(50),
            'number' => TextInput::make($field->key)->numeric(),
            'textarea' => Textarea::make($field->key)->rows(3),
            'date' => DatePicker::make($field->key),
            'select' => Select::make($field->key)
                ->options($field->optionLabels()),
            'multiselect' => Select::make($field->key)
                ->multiple()
                ->options($field->optionLabels()),
            default => TextInput::make($field->key)->maxLength(255),
        };

        $component->label($field->name);

        if ($field->unique) {
            $component->rules([static::uniqueRule($field, $valuesModel)]);
        }

        return $component;
    }

    /** @param  class-string<Model>  $valuesModel */
    protected static function uniqueRule(CustomField|CompanyCustomField $field, string $valuesModel): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($field, $valuesModel, $record): void {
            if (blank($value)) {
                return;
            }

            $isTaken = $valuesModel::query()
                ->where('organization_id', Filament::getTenant()?->getKey())
                ->whereCustomFieldValue($field->key, $value)
                ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($isTaken) {
                $fail("The {$field->name} must be unique.");
            }
        };
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    public static function mergeWithStoredValues(?array $state, ?Model $record): array
    {
        return array_replace($record?->custom_field_values ?? [], $state ?? []);
    }
}
