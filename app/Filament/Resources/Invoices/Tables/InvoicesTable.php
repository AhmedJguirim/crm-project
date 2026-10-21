<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\DealStatus;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoicePaymentRefused;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceLedger;
use App\Support\LocalTime;
use App\Support\MoneyLimit;
use App\Support\NotificationText;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

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
                self::recordPaymentAction(),

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
     * Records a payment on the invoice, the balance being prefilled so that settling an invoice takes one confirmation.
     */
    public static function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->authorize('update')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true))
            ->modalHeading('Record a payment')
            ->modalSubmitActionLabel('Record payment')
            ->fillForm(fn (Invoice $record): array => self::ledgerFormDefaults(
                number_format(max(0, InvoiceLedger::cents($record->amount) - InvoiceLedger::cents($record->amount_paid)) / 100, 2, '.', ''),
            ))
            ->schema(self::ledgerFields(InvoicePaymentKind::Payment))
            ->action(fn (Invoice $record, array $data, Action $action) => self::recordLedgerEntry($record, InvoicePaymentKind::Payment, $data, $action));
    }

    /**
     * Records a refund on the invoice, for the admins and the owner. Recorded entries can't be edited or deleted.
     */
    public static function recordRefundAction(): Action
    {
        return Action::make('recordRefund')
            ->authorize('refund')
            ->label('Record refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => (float) $record->amount_paid > 0)
            ->modalHeading('Record a refund')
            ->modalDescription("Refunds can't be edited or deleted once recorded.")
            ->modalSubmitActionLabel('Record refund')
            ->fillForm(fn (Invoice $record): array => self::ledgerFormDefaults(number_format((float) $record->amount_paid, 2, '.', '')))
            ->schema(self::ledgerFields(InvoicePaymentKind::Refund))
            ->action(fn (Invoice $record, array $data, Action $action) => self::recordLedgerEntry($record, InvoicePaymentKind::Refund, $data, $action));
    }

    /**
     * @return array<string, mixed>
     */
    private static function ledgerFormDefaults(string $amount): array
    {
        return [
            'amount' => $amount,
            'paid_on' => LocalTime::todayDate(),
            'method' => null,
            'reference' => null,
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function ledgerFields(InvoicePaymentKind $kind): array
    {
        return [
            TextInput::make('amount')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue(MoneyLimit::MAX)
                ->step(0.01)
                ->prefix(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                ->rule(fn (Invoice $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $kind): void {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $cents = InvoiceLedger::cents($value);
                    $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

                    if ($kind === InvoicePaymentKind::Payment) {
                        $balance = InvoiceLedger::cents($record->amount) - InvoiceLedger::cents($record->amount_paid);

                        if ($cents > $balance) {
                            $fail("The payment can't be more than the balance due (".Number::currency($balance / 100, $currency).').');
                        }

                        return;
                    }

                    $paid = InvoiceLedger::cents($record->amount_paid);

                    if ($cents > $paid) {
                        $fail("The refund can't be more than the amount paid (".Number::currency($paid / 100, $currency).').');
                    }
                }),

            DatePicker::make('paid_on')
                ->label('Date')
                ->required()
                ->maxDate(fn (): string => LocalTime::todayDate()),

            Select::make('method')
                ->label('Method')
                ->options(PaymentMethod::class)
                ->nullable(),

            TextInput::make('reference')
                ->label('Reference')
                ->maxLength(255)
                ->nullable()
                ->helperText('Transfer reference, cheque number…'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function recordLedgerEntry(Invoice $record, InvoicePaymentKind $kind, array $data, Action $action): void
    {
        $isPayment = $kind === InvoicePaymentKind::Payment;

        try {
            app(InvoiceLedger::class)->record(
                $record,
                $kind,
                number_format((float) $data['amount'], 2, '.', ''),
                (string) $data['paid_on'],
                $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::tryFrom((string) ($data['method'] ?? '')),
                filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
                auth()->user(),
            );
        } catch (InvoicePaymentRefused $exception) {
            Notification::make()
                ->danger()
                ->title(NotificationText::plain($isPayment ? 'Payment not recorded' : 'Refund not recorded'))
                ->body(NotificationText::plain($exception->getMessage()))
                ->send();

            $action->halt();

            return;
        }

        Notification::make()
            ->success()
            ->title(NotificationText::plain($isPayment ? 'Payment recorded' : 'Refund recorded'))
            ->send();
    }
}
