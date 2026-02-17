<?php

namespace App\Filament\Resources\Contacts\Schemas;

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

                TextInput::make('phone')
                    ->maxLength(50)
                    ->nullable(),

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
