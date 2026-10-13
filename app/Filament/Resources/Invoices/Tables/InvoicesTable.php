<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Models\Invoice;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->money(fn (): string => Filament::getTenant()?->currencyCode() ?? 'USD')
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
                    ->color(fn (Invoice $record): ?string => $record->isPastDue() ? 'danger' : null)
                    ->sortable(),

                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                OptionSelectFilter::make('status')
                    ->options(InvoiceStatus::class)
                    ->multiple(),

                RecordKeySelectFilter::make('contact_id')
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
                            ->when(self::filterDate($data['from'] ?? null), fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '>=', $date))
                            ->when(self::filterDate($data['until'] ?? null), fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '<=', $date));
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
                            'paid_at' => $record->paid_at ?? LocalTime::todayDate(),
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

    /**
     * A forged filter value is only used when it is a real `Y-m-d` date (BUG-32).
     */
    private static function filterDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
