<?php

namespace App\Filament\Resources\CompanyCustomFields\Schemas;

use App\Filament\Support\CustomFields\CustomFieldDefinitionFields;
use App\Models\CompanyCustomField;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CompanyCustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(CompanyCustomField::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', Filament::getTenant()->id);
                    })
                    ->validationMessages(['unique' => CustomFieldDefinitionFields::nameTakenMessage()])
                    ->helperText('A descriptive name for this custom field'),

                CustomFieldDefinitionFields::key(),

                CustomFieldDefinitionFields::type(),

                CustomFieldDefinitionFields::options()
                    ->rule(CustomFieldDefinitionFields::optionsInUseRule()),

                Toggle::make('unique')
                    ->label('Unique Value')
                    ->helperText('Require unique values for this field across all companies')
                    ->default(false),

                Select::make('position')
                    ->label('Position')
                    ->options(function ($record) {
                        $fields = CompanyCustomField::query()
                            ->where('organization_id', Filament::getTenant()->id)
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
                    ->helperText('Where to place this field among the company fields'),
            ]);
    }
}
