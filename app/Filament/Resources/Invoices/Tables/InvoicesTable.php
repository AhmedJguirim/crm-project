<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->searchable()
                    ->url(fn (Invoice $record): string => ContactResource::getUrl('view', ['record' => $record->contact]))
                    ->openUrlInNewTab(),

                TextColumn::make('deal.title')
                    ->label('Deal')
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state, Invoice $record): string {
                        if (! $record->deal) {
                            return '—';
                        }

                        if ($record->deal->stage->value === 'lost') {
                            return sprintf('%s (Lost)', $record->deal->title);
                        }

                        return $state ?? $record->deal->title;
                    })
                    ->url(fn (Invoice $record): ?string => $record->deal
                        ? DealResource::getUrl('view', ['record' => $record->deal])
                        : null),

                TextColumn::make('amount')
                    ->money(fn (Invoice $record): string => $record->currency)
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->date()
                    ->sortable(),

                TextColumn::make('due_at')
                    ->label('Due')
                    ->date()
                    ->color(function (Invoice $record): ?string {
                        return $record->due_at && $record->due_at->isPast() && in_array($record->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true)
                            ? 'danger'
                            : null;
                    })
                    ->sortable(),

                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class)
                    ->multiple(),

                SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('issued_at_range')
                    ->label('Issued Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '<=', $date));
                    }),

                Filter::make('overdue')
                    ->label('Overdue only')
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->recordActions([
                Action::make('markAsPaid')
                    ->authorize('update')
                    ->label('Mark as Paid')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true))
                    ->action(function (Invoice $record): void {
                        $record->update([
                            'status' => InvoiceStatus::Paid,
                            'paid_at' => $record->paid_at ?? today(),
                        ]);
                    }),

                Action::make('downloadPdf')
                    ->authorize('view')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (Invoice $record): string => route('invoices.pdf', $record))
                    ->openUrlInNewTab(),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->defaultSort('due_at')
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('Create your first invoice from a deal or start from scratch.');
    }
}
