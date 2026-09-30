<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Support\CustomFields\CustomFieldValuesSection;
use App\Models\Contact;
use App\Models\CustomField;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

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

                Select::make('tags')
                    ->multiple()
                    ->relationship(
                        'tags',
                        'name',
                        fn ($query) => $query->where('tags.organization_id', Filament::getTenant()->id)
                    )
                    ->preload()
                    ->searchable(),

                Select::make('companies')
                    ->multiple()
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
