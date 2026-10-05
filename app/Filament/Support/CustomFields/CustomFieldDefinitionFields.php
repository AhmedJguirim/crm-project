<?php

namespace App\Filament\Support\CustomFields;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use App\Support\CustomFields\OptionUsage;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Form components shared by the contact and company custom field definition forms.
 */
class CustomFieldDefinitionFields
{
    /** @var array<string, string> */
    public const TYPES = [
        'text' => 'Text',
        'email' => 'Email',
        'url' => 'URL',
        'phone' => 'Phone',
        'number' => 'Number',
        'date' => 'Date',
        'textarea' => 'Text Area',
        'select' => 'Select (Dropdown)',
        'multiselect' => 'Multi-Select',
    ];

    /** @var array<int, string> */
    public const TYPES_WITH_OPTIONS = ['select', 'multiselect'];

    public static function nameTakenMessage(): string
    {
        return 'A custom field with this name already exists. It may be a deleted one: '
            .'check the "Trashed" filter of the list and restore it instead of creating a new field.';
    }

    public static function key(): TextInput
    {
        return TextInput::make('key')
            ->label('Key')
            ->disabled()
            ->dehydrated(false)
            ->visibleOn('edit')
            ->helperText('Random identifier generated on creation. It never changes, even if the field is renamed.');
    }

    public static function type(): Select
    {
        return Select::make('type')
            ->required()
            ->options(self::TYPES)
            ->live()
            ->disabled(fn (?Model $record): bool => $record !== null)
            ->helperText(fn (?Model $record): string => $record !== null
                ? "The type can't change after the field is created. To store another kind of data, create a new field."
                : 'The type of data this field will store');
    }

    public static function options(): Repeater
    {
        return Repeater::make('options')
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Display text for this option'),

                TextInput::make('value')
                    ->required()
                    ->maxLength(255)
                    ->distinct()
                    ->helperText('Stored value for this option'),
            ])
            ->visible(fn (Get $get): bool => in_array($get('type'), self::TYPES_WITH_OPTIONS))
            ->required(fn (Get $get): bool => in_array($get('type'), self::TYPES_WITH_OPTIONS))
            ->minItems(1)
            ->defaultItems(0)
            ->addActionLabel('Add Option')
            ->reorderable()
            ->helperText('Define the available options for this field');
    }

    /**
     * Refuses to remove, or to change the stored value of, an option that records still store. Editing only a label
     * is fine: stored values don't change.
     */
    public static function optionsInUseRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || ! $record instanceof CustomField && ! $record instanceof CompanyCustomField) {
                return;
            }

            $keptValues = collect($value)->pluck('value')->map(fn (mixed $optionValue): string => (string) $optionValue)->all();
            $removedValues = array_values(array_diff(array_keys($record->optionLabels()), $keptValues));

            $message = OptionUsage::describeRemovedOptions($record, array_map('strval', $removedValues));

            if ($message !== null) {
                $fail($message);
            }
        };
    }
}
