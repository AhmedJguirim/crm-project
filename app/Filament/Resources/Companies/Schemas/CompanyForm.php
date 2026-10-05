<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Filament\Support\AbilityCheck;
use App\Filament\Support\CustomFields\CustomFieldValuesSection;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
                            ->createOptionAction(fn (Action $action): Action => $action->authorize(AbilityCheck::for('create', CompanyType::class)))
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(CompanyType::class, 'name', modifyRuleUsing: fn ($rule) => $rule
                                        ->where('organization_id', Filament::getTenant()?->getKey())
                                        ->whereNull('deleted_at')),
                            ]),

                        TextInput::make('website')
                            ->placeholder('acme.com')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && Company::domainFrom((string) $value) === null) {
                                    $fail('Enter a website like acme.com or https://acme.com.');
                                }
                            })
                            ->hint(fn (Get $get, ?Company $record): ?string => static::sharedDomainHint($get('website'), $record))
                            ->hintColor('warning')
                            ->hintIcon(Heroicon::ExclamationTriangle),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),

                        TextInput::make('employees')
                            ->label('Employees')
                            ->integer()
                            ->minValue(0),

                        TextInput::make('annual_revenue')
                            ->label('Annual revenue')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue('9999999999999.99'),

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
                    fn (): Collection => static::customFields(),
                    Company::class,
                ),
            ]);
    }

    /**
     * A warning, never an error: other non-trashed companies of the organization that already use the same domain.
     */
    protected static function sharedDomainHint(mixed $website, ?Company $record): ?string
    {
        $domain = Company::domainFrom(is_string($website) ? $website : null);

        if ($domain === null) {
            return null;
        }

        $others = Company::query()
            ->where('domain', $domain)
            ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->orderBy('name');

        $count = (clone $others)->count();

        if ($count === 0) {
            return null;
        }

        $names = (clone $others)->limit(3)->pluck('name')->implode(', ');

        return 'Also used by: '.$names.($count > 3 ? ' and '.($count - 3).' more' : '');
    }

    /** @return Collection<int, CompanyCustomField> */
    public static function customFields(): Collection
    {
        return CompanyCustomField::query()->orderBy('order')->get();
    }
}
