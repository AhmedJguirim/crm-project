<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Summary')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('invoice_number')
                            ->label('Invoice #')
                            ->weight('bold'),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('contact.name')
                            ->label('Contact')
                            ->placeholder('—'),

                        TextEntry::make('deal.title')
                            ->label('Deal')
                            ->placeholder('No deal linked'),

                        TextEntry::make('amount')
                            ->money(fn ($record): string => $record->currency)
                            ->label('Amount'),

                        TextEntry::make('currency'),

                        TextEntry::make('issued_at')
                            ->label('Issued')
                            ->date(),

                        TextEntry::make('due_at')
                            ->label('Due')
                            ->date(),

                        TextEntry::make('paid_at')
                            ->label('Paid')
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
