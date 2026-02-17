<?php

namespace App\Filament\Resources\CustomFields\Schemas;

use App\Models\CustomField;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(CustomField::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', Filament::getTenant()->id);
                    })
                    ->helperText('A descriptive name for this custom field'),

                Select::make('type')
                    ->required()
                    ->options([
                        'text' => 'Text',
                        'email' => 'Email',
                        'url' => 'URL',
                        'phone' => 'Phone',
                        'number' => 'Number',
                        'date' => 'Date',
                        'textarea' => 'Text Area',
                        'select' => 'Select (Dropdown)',
                        'multiselect' => 'Multi-Select',
                    ])
                    ->live()
                    ->helperText('The type of data this field will store'),

                Repeater::make('options')
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
                    ->visible(fn ($get) => in_array($get('type'), ['select', 'multiselect']))
                    ->required(fn ($get) => in_array($get('type'), ['select', 'multiselect']))
                    ->minItems(1)
                    ->defaultItems(0)
                    ->addActionLabel('Add Option')
                    ->reorderable()
                    ->helperText('Define the available options for this field'),

                Toggle::make('unique')
                    ->label('Unique Value')
                    ->helperText('Require unique values for this field across all contacts')
                    ->default(false),
                // TODO: make ordering more effective
                Select::make('position')
                    ->label('Position')
                    ->options(function ($record) {
                        $fields = CustomField::where('organization_id', Filament::getTenant()->id)
                            ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                            ->orderBy('order')
                            ->get();

                        $options = [
                            'beginning' => 'At the beginning',
                            'end' => 'At the end',
                        ];

                        foreach ($fields as $field) {
                            $options["after_{$field->id}"] = "After: {$field->name}";
                        }

                        return $options;
                    })
                    ->default('end')
                    ->helperText('Where to place this field in the list'),
            ]);
    }
}
