<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\CompanyIndustry;
use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Resources\CompanyTypes\Schemas\CompanyTypeForm;
use App\Filament\Support\AbilityCheck;
use App\Filament\Support\CustomFields\CustomFieldValuesSection;
use App\Filament\Support\RecordKeyField;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Services\Contacts\PossibleDuplicateContacts;
use App\Services\Owners\OwnerOptions;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->hint(fn (Get $get, ?Contact $record): ?string => static::sameNameHint($get, $record))
                    ->hintColor('warning')
                    ->hintIcon(fn (Get $get, ?Contact $record): ?Heroicon => filled(static::sameNameHint($get, $record)) ? Heroicon::ExclamationTriangle : null)
                    ->hintIconTooltip('Another contact in this organization has the same name and works for one of the same companies. This is only a warning: you can still save.'),

                TextInput::make('email')
                    ->nullable()
                    ->email()
                    ->maxLength(255)
                    ->mutateStateForValidationUsing(fn (mixed $state): mixed => is_string($state) ? mb_strtolower(trim($state)) : $state)
                    ->unique(Contact::class, 'email', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', CurrentOrganization::get()->id);
                    }),

                Select::make('status')
                    ->options(ContactStatus::class)
                    ->default(ContactStatus::Lead)
                    ->placeholder('Select status...'),

                TextInput::make('phone')
                    ->maxLength(50)
                    ->nullable()
                    ->live(onBlur: true)
                    ->hint(fn (Get $get, ?Contact $record): ?string => static::samePhoneHint($get, $record))
                    ->hintColor('warning')
                    ->hintIcon(fn (Get $get, ?Contact $record): ?Heroicon => filled(static::samePhoneHint($get, $record)) ? Heroicon::ExclamationTriangle : null)
                    ->hintIconTooltip('Another contact in this organization has the same phone number. This is only a warning: you can still save.'),

                Select::make('lead_source')
                    ->label('Lead Source')
                    ->options(LeadSource::class)
                    ->placeholder('Select source...'),

                RecordKeyField::select('owner_id')
                    ->label('Contact owner')
                    ->options(fn (?Contact $record): array => OwnerOptions::forForm($record))
                    ->in(fn (?Contact $record): array => array_keys(OwnerOptions::forForm($record)))
                    ->default(fn (): ?int => Auth::id())
                    ->searchable()
                    ->nullable()
                    ->placeholder('No owner'),

                RecordKeyField::multiSelect('tags')
                    ->relationship(
                        'tags',
                        'name',
                        fn ($query) => $query->where('tags.organization_id', CurrentOrganization::get()->id)
                    )
                    ->preload()
                    ->searchable(),

                RecordKeyField::multiSelect('companies')
                    ->relationship('companies', 'name')
                    ->preload()
                    ->searchable()
                    ->live()
                    ->helperText('Companies this contact works for')
                    ->createOptionForm([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('website')
                            ->url()
                            ->maxLength(255)
                            ->nullable(),

                        Select::make('industry')
                            ->options(CompanyIndustry::class)
                            ->searchable(),

                        RecordKeyField::select('company_type_id')
                            ->label('Company type')
                            ->relationship('companyType', 'name')
                            ->searchable()
                            ->preload()
                            ->getOptionLabelUsing(fn (mixed $value, ?Company $record): ?string => CompanyType::query()
                                ->when($record !== null && (int) $value === $record->company_type_id, fn (Builder $query): Builder => $query->withTrashed())
                                ->find($value)
                                ?->companyTypeLabel())
                            ->createOptionAction(fn (Action $action): Action => $action->authorize(AbilityCheck::for('create', CompanyType::class)))
                            ->createOptionForm([
                                CompanyTypeForm::nameInput(),
                            ]),
                    ]),

                CustomFieldValuesSection::make(
                    fn (): Collection => CustomField::query()->orderBy('order')->get(),
                    Contact::class,
                ),
            ]);
    }

    protected static function samePhoneHint(Get $get, ?Contact $record): ?string
    {
        return static::possibleDuplicatesHint('Same phone as', PossibleDuplicateContacts::withSamePhone($get('phone'), $record));
    }

    protected static function sameNameHint(Get $get, ?Contact $record): ?string
    {
        return static::possibleDuplicatesHint('Same name and company as', PossibleDuplicateContacts::withSameNameAndCompany($get('name'), $get('companies'), $record));
    }

    /**
     * A warning, never an error: the first three matching contacts (name, then email when they have one) as plain text.
     *
     * @param  Builder<Contact>|null  $matches
     */
    protected static function possibleDuplicatesHint(string $prefix, ?Builder $matches): ?string
    {
        if ($matches === null) {
            return null;
        }

        $count = (clone $matches)->count();

        if ($count === 0) {
            return null;
        }

        $names = (clone $matches)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'name', 'email'])
            ->map(fn (Contact $contact): string => $contact->name.(filled($contact->email) ? ' ('.$contact->email.')' : ''))
            ->implode(', ');

        return $prefix.': '.$names.($count > 3 ? ' and '.($count - 3).' more' : '');
    }
}
