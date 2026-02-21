<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Models\Contact;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

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

                ...ContactCustomFieldsSchema::buildComponents(),
            ]);
    }
}
