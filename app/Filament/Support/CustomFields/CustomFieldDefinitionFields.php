<?php

namespace App\Filament\Support\CustomFields;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

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
            ->helperText('The type of data this field will store');
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
}
