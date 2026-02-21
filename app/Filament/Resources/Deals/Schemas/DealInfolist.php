<?php

namespace App\Filament\Resources\Deals\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DealInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Deal Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')
                            ->columnSpanFull(),

                        TextEntry::make('contact.name')
                            ->label('Contact')
                            ->placeholder('No contact'),

                        TextEntry::make('value')
                            ->money(fn ($record): string => $record->currency)
                            ->placeholder('—'),

                        TextEntry::make('stage')
                            ->badge(),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('expected_close_date')
                            ->label('Expected Close Date')
                            ->date()
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M j, Y g:i A'),

                        TextEntry::make('notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
