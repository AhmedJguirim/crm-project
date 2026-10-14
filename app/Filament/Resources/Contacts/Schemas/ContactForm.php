<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Support\CustomFields\CustomFieldValuesSection;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\CustomField;
use App\Services\Owners\OwnerOptions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
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
                    ->maxLength(255),

                TextInput::make('email')
                    ->required()
                    ->email()
                    ->maxLength(255)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : mb_strtolower(trim($state)))
                    ->unique(Contact::class, 'email', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', Filament::getTenant()->id);
                    }),

                Select::make('status')
                    ->options(ContactStatus::class)
                    ->default(ContactStatus::Lead)
                    ->placeholder('Select status...'),

                TextInput::make('phone')
                    ->maxLength(50)
                    ->nullable(),

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
                        fn ($query) => $query->where('tags.organization_id', Filament::getTenant()->id)
                    )
                    ->preload()
                    ->searchable(),

                RecordKeyField::multiSelect('companies')
                    ->relationship('companies', 'name')
                    ->preload()
                    ->searchable()
                    ->helperText('Companies this contact works for'),

                CustomFieldValuesSection::make(
                    fn (): Collection => CustomField::query()->orderBy('order')->get(),
                    Contact::class,
                ),
            ]);
    }
}
