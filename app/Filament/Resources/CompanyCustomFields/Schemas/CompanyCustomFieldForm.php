<?php

namespace App\Filament\Resources\CompanyCustomFields\Schemas;

use App\Filament\Support\CustomFields\CustomFieldDefinitionFields;
use App\Models\CompanyCustomField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CompanyCustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_type_id')
                    ->label('Company Type')
                    ->relationship('companyType', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->disabledOn('edit')
                    ->helperText('The company type this field applies to'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(CompanyCustomField::class, 'name', modifyRuleUsing: function ($rule, Get $get) {
                        return $rule->where('company_type_id', $get('company_type_id'));
                    })
                    ->validationMessages(['unique' => CustomFieldDefinitionFields::nameTakenMessage()])
                    ->helperText('A descriptive name for this custom field'),

                CustomFieldDefinitionFields::key(),

                CustomFieldDefinitionFields::type(),

                CustomFieldDefinitionFields::options(),

                Toggle::make('unique')
                    ->label('Unique Value')
                    ->helperText('Require unique values for this field across all companies')
                    ->default(false),

                Select::make('position')
                    ->label('Position')
                    ->options(function ($record, Get $get) {
                        $fields = CompanyCustomField::query()
                            ->where('company_type_id', $record?->company_type_id ?? $get('company_type_id'))
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
                    ->helperText('Where to place this field among the fields of this company type'),
            ]);
    }
}
