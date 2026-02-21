<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStage;
use App\Models\Contact;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class DealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                Select::make('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->rules([
                        fn () => Rule::exists(Contact::class, 'id')->where(
                            fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                        ),
                    ]),

                Select::make('stage')
                    ->options(DealStage::class)
                    ->default(DealStage::Lead)
                    ->required(),

                TextInput::make('value')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),

                Select::make('currency')
                    ->options([
                        'USD' => 'USD',
                        'EUR' => 'EUR',
                        'GBP' => 'GBP',
                    ])
                    ->default('USD')
                    ->required(),

                DatePicker::make('expected_close_date')
                    ->label('Expected Close Date')
                    ->nullable(),

                Textarea::make('notes')
                    ->nullable()
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
