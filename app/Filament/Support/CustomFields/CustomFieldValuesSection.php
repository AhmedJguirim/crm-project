<?php

namespace App\Filament\Support\CustomFields;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Renders and validates the custom field values of a record (contact, company, ...).
 *
 * Values are read from and written to the record's `custom_field_values` attribute,
 * keyed by each field's immutable key. Only the fields the user added (or that already
 * hold a value) are rendered, and only non-blank values are stored. Stored values of
 * keys without a resolved definition (for example fields of another company type)
 * are kept untouched on save.
 */
class CustomFieldValuesSection
{
    protected const PICKER_KEY = 'custom_field_picker';

    protected const ACTIVE_KEYS = 'active_custom_field_keys';

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
            ->collapsible()
            ->columnSpanFull()
            ->hidden(fn (Get $get): bool => $resolveFields($get)->isEmpty())
            ->schema([
                Select::make(static::PICKER_KEY)
                    ->label('Add a field')
                    ->placeholder('Search fields by label...')
                    ->searchable()
                    ->live()
                    ->dehydrated(false)
                    ->options(fn (Get $get): array => static::availableOptions($resolveFields($get), $get(static::ACTIVE_KEYS)))
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($resolveFields): void {
                        if (blank($state)) {
                            return;
                        }

                        $set(static::ACTIVE_KEYS, [...static::activeKeys($get(static::ACTIVE_KEYS)), $state]);
                        $set(static::PICKER_KEY, null);

                        if ($resolveFields($get)->firstWhere('key', $state)?->type === 'multiselect') {
                            $set('custom_field_values.'.$state, []);
                        }
                    }),

                Hidden::make(static::ACTIVE_KEYS)
                    ->default([])
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Hidden $component, ?Model $record): void {
                        $component->state(array_keys(array_filter(
                            $record?->custom_field_values ?? [],
                            fn (mixed $value): bool => filled($value),
                        )));
                    }),

                Group::make()
                    ->statePath('custom_field_values')
                    ->schema(fn (Get $get): array => static::components(
                        static::activeFields($resolveFields($get), $get(static::ACTIVE_KEYS)),
                        $valuesModel,
                    ))
                    ->dehydrateStateUsing(fn (?array $state, Get $get, ?Model $record): array => static::mergeWithStoredValues(
                        $state,
                        $record,
                        $resolveFields($get)->pluck('key')->all(),
                    )),
            ]);
    }

    /**
     * @param  Collection<int, CustomField|CompanyCustomField>  $fields
     * @return array<string, string>
     */
    protected static function availableOptions(Collection $fields, mixed $activeKeys): array
    {
        return $fields
            ->reject(fn (CustomField|CompanyCustomField $field): bool => in_array($field->key, static::activeKeys($activeKeys), true))
            ->pluck('name', 'key')
            ->all();
    }

    /**
     * @param  Collection<int, CustomField|CompanyCustomField>  $fields
     * @return Collection<int, CustomField|CompanyCustomField>
     */
    protected static function activeFields(Collection $fields, mixed $activeKeys): Collection
    {
        return $fields
            ->filter(fn (CustomField|CompanyCustomField $field): bool => in_array($field->key, static::activeKeys($activeKeys), true))
            ->values();
    }

    /** @return array<int, string> */
    protected static function activeKeys(mixed $activeKeys): array
    {
        return is_array($activeKeys) ? $activeKeys : [];
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

        $component
            ->label($field->name)
            ->hintAction(static::removeAction($field));

        if ($field->unique) {
            $component->rules([static::uniqueRule($field, $valuesModel)]);
        }

        return $component;
    }

    protected static function removeAction(CustomField|CompanyCustomField $field): Action
    {
        return Action::make("remove_{$field->key}")
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->action(function (Get $get, Set $set) use ($field): void {
                $set('../'.static::ACTIVE_KEYS, array_values(array_diff(
                    static::activeKeys($get('../'.static::ACTIVE_KEYS)),
                    [$field->key],
                )));
                $set($field->key, null);
            });
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
     * @param  array<int, string>  $definedKeys  keys of the definitions resolved for the record
     * @return array<string, mixed>
     */
    public static function mergeWithStoredValues(?array $state, ?Model $record, array $definedKeys = []): array
    {
        $keptValues = array_diff_key($record?->custom_field_values ?? [], array_flip($definedKeys));

        return array_replace($keptValues, array_filter($state ?? [], fn (mixed $value): bool => filled($value)));
    }
}
