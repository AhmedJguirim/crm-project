<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoicePaymentKind;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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
                            ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                            ->label('Amount'),

                        TextEntry::make('amount_paid')
                            ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                            ->label('Amount Paid'),

                        TextEntry::make('payment_terms')
                            ->label('Payment Terms')
                            ->formatStateUsing(fn (?int $state): string => match ($state) {
                                0 => 'Due on Receipt',
                                default => "Net {$state}",
                            }),

                        TextEntry::make('balance')
                            ->label('Balance Due')
                            ->state(fn ($record): string => number_format((float) $record->amount - (float) $record->amount_paid, 2).' '.(CurrentOrganization::find()?->currencyCode() ?? 'USD'))
                            ->color(fn ($record): ?string => (float) $record->amount - (float) $record->amount_paid > 0 ? 'danger' : 'success'),

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

                Section::make('Payments')
                    ->visible(fn (Invoice $record): bool => $record->payments()->exists())
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->state(fn (Invoice $record) => $record->payments()->with('recordedBy')->orderBy('paid_on')->orderBy('id')->get())
                            ->table([
                                TableColumn::make('Date'),
                                TableColumn::make('Type'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Method'),
                                TableColumn::make('Reference'),
                                TableColumn::make('Recorded by'),
                            ])
                            ->schema([
                                TextEntry::make('paid_on')
                                    ->date(),

                                TextEntry::make('kind')
                                    ->badge(),

                                TextEntry::make('amount')
                                    ->state(fn (InvoicePayment $record): float => $record->kind === InvoicePaymentKind::Refund
                                        ? -(float) $record->amount
                                        : (float) $record->amount)
                                    ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD'),

                                TextEntry::make('method')
                                    ->placeholder('—'),

                                TextEntry::make('reference')
                                    ->placeholder('—'),

                                TextEntry::make('recordedBy.name')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
