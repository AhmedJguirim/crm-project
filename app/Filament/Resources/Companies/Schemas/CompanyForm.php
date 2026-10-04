<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Filament\Support\AbilityCheck;
use App\Filament\Support\CustomFields\CustomFieldValuesSection;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('company_type_id')
                            ->label('Type')
                            ->relationship('companyType', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->createOptionAction(fn (Action $action): Action => $action->authorize(AbilityCheck::for('create', CompanyType::class)))
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(CompanyType::class, 'name', modifyRuleUsing: fn ($rule) => $rule
                                        ->where('organization_id', Filament::getTenant()?->getKey())
                                        ->whereNull('deleted_at')),
                            ])
                            ->helperText('The type decides which custom fields are available.'),

                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Address')
                    ->relationship(
                        'address',
                        condition: fn (Get $get, ?Company $record): bool => filled($record?->address_id)
                            || filled(array_filter($get('address') ?? [])),
                    )
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('street')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('city')
                            ->maxLength(255),

                        TextInput::make('zip')
                            ->label('ZIP / Postal code')
                            ->maxLength(255),

                        TextInput::make('country')
                            ->maxLength(255),
                    ]),

                CustomFieldValuesSection::make(
                    fn (Get $get): Collection => static::customFieldsForType($get('company_type_id')),
                    Company::class,
                ),
            ]);
    }

    /** @return Collection<int, CompanyCustomField> */
    public static function customFieldsForType(mixed $companyTypeId): Collection
    {
        if (blank($companyTypeId)) {
            return new Collection;
        }

        return CompanyCustomField::query()
            ->where('company_type_id', $companyTypeId)
            ->orderBy('order')
            ->get();
    }
}
