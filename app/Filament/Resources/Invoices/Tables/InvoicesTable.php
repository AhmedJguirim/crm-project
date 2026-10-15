<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Models\Invoice;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
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

                        if ($record->deal->status === DealStatus::Lost) {
                            return sprintf('%s (Lost)', $record->deal->title);
                        }

                        return $state ?? $record->deal->title;
                    })
                    ->url(fn (Invoice $record): ?string => $record->deal
                        ? DealResource::getUrl('view', ['record' => $record->deal])
                        : null),

                TextColumn::make('amount')
                    ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
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
                            ->when(LocalTime::filterDate($data['from'] ?? null), fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '>=', $date))
                            ->when(LocalTime::filterDate($data['until'] ?? null), fn (Builder $builder, $date): Builder => $builder->whereDate('issued_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        foreach (['from' => 'Issued from', 'until' => 'Issued until'] as $bound => $label) {
                            $date = LocalTime::filterDate($data[$bound] ?? null);

                            if ($date === null) {
                                continue;
                            }

                            $indicators[] = Indicator::make($label.' '.CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('M j, Y'))->removeField($bound);
                        }

                        return $indicators;
                    }),

                Filter::make('overdue')
                    ->label('Overdue only')
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Invoice> $query */
                        return $query->overdue();
                    }),
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
}
