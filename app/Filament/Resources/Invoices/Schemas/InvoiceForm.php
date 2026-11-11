<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceLedger;
use App\Support\LocalTime;
use App\Support\MoneyLimit;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                RecordKeyField::select('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                    )
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('deal_id', null);
                        $set('amount', null);
                    })
                    ->rules([
                        fn () => Rule::exists(Contact::class, 'id')->where(
                            fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                        ),
                    ]),

                RecordKeyField::select('deal_id')
                    ->label('Deal')
                    ->nullable()
                    ->searchable()
                    ->live()
                    ->options(function (Get $get): array {
                        $contactId = $get('contact_id');
                        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

                        if (! $contactId) {
                            return [];
                        }

                        return Deal::query()
                            ->where('contact_id', $contactId)
                            ->orderByDesc('created_at')
                            ->get()
                            ->mapWithKeys(fn (Deal $deal): array => [
                                $deal->id => $deal->value
                                    ? "{$deal->title} — ".number_format((float) $deal->value, 2)." {$currency}"
                                    : $deal->title,
                            ])
                            ->all();
                    })
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (! $state || self::currencyCode($get('currency')) !== self::homeCurrencyCode()) {
                            return;
                        }

                        $deal = Deal::find($state);

                        if (! $deal) {
                            return;
                        }

                        if ($deal->value) {
                            $set('amount', $deal->value);
                        }
                    })
                    ->helperText(fn (Get $get): ?string => $get('contact_id')
                        ? null
                        : 'Select a contact first'),

                TextInput::make('invoice_number')
                    ->label('Invoice #')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (): string => self::nextInvoiceNumber())
                    ->readOnly()
                    ->unique(
                        table: Invoice::class,
                        column: 'invoice_number',
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('organization_id', CurrentOrganization::find()?->id)
                    ),

                Select::make('currency')
                    ->label('Currency')
                    ->options(fn (?Invoice $record): array => self::currencyOptions($record))
                    ->default(fn (): string => self::homeCurrencyCode())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->disabled(fn (?Invoice $record): bool => self::isIssued($record))
                    ->helperText(fn (?Invoice $record): ?string => self::isIssued($record)
                        ? "The currency and exchange rate can't change once the invoice is issued."
                        : null)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $currency = Currency::tryFrom((string) $state);

                        if ($currency === null || $currency->value === self::homeCurrencyCode()) {
                            $set('exchange_rate', null);

                            return;
                        }

                        $set('exchange_rate', Invoice::latestExchangeRate($currency));
                    }),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(MoneyLimit::MAX)
                    ->step(0.01)
                    ->prefix(fn (Get $get): string => self::currencyCode($get('currency')))
                    ->disabled(fn (?Invoice $record): bool => $record?->payments()->exists() ?? false)
                    ->helperText(fn (?Invoice $record): ?string => $record?->payments()->exists()
                        ? "The amount can't change once a payment is recorded."
                        : null),

                self::exchangeRateField(fn (Get $get): string => '1 '.self::currencyCode($get('currency')).' =')
                    ->visible(fn (Get $get): bool => self::currencyCode($get('currency')) !== self::homeCurrencyCode())
                    ->required(fn (Get $get): bool => self::issuesInvoice($get('status')))
                    ->disabled(fn (?Invoice $record): bool => self::isIssued($record))
                    ->afterStateHydrated(function (TextInput $component, mixed $state, ?Invoice $record): void {
                        if ($state !== null) {
                            $component->state(Invoice::trimExchangeRate((string) $state));

                            return;
                        }

                        if ($record?->status === InvoiceStatus::Draft && $record->currency->value !== self::homeCurrencyCode()) {
                            $component->state(Invoice::latestExchangeRate($record->currency));
                        }
                    }),

                Select::make('payment_terms')
                    ->label('Payment Terms')
                    ->options([
                        7 => 'Net 7',
                        14 => 'Net 14',
                        15 => 'Net 15',
                        30 => 'Net 30',
                        45 => 'Net 45',
                        60 => 'Net 60',
                        90 => 'Net 90',
                        0 => 'Due on Receipt',
                    ])
                    ->default(30)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        $issuedAt = $get('issued_at');

                        if (! $issuedAt || $state === null) {
                            return;
                        }

                        $set('due_at', now()->parse($issuedAt)->addDays((int) $state)->format('Y-m-d'));
                    }),

                Select::make('status')
                    ->options(fn (?Invoice $record): array => self::statusOptions($record))
                    ->default(InvoiceStatus::Draft)
                    ->required()
                    ->disabled(fn (?Invoice $record): bool => self::isSetByPayments($record))
                    ->helperText(fn (?Invoice $record): ?string => self::isSetByPayments($record) ? 'Set by the payments recorded.' : null)
                    ->hiddenOn('create'),

                DatePicker::make('issued_at')
                    ->label('Issued')
                    ->default(fn (): string => LocalTime::todayDate())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (! $state) {
                            return;
                        }

                        $terms = (int) ($get('payment_terms') ?? 30);
                        $set('due_at', now()->parse($state)->addDays($terms)->format('Y-m-d'));
                    }),

                DatePicker::make('due_at')
                    ->label('Due')
                    ->required()
                    ->default(fn (): string => LocalTime::now()->addDays(30)->toDateString()),

                Textarea::make('notes')
                    ->rows(4)
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The currency code of the current organization (USD when it has none).
     */
    public static function homeCurrencyCode(): string
    {
        return CurrentOrganization::find()?->currencyCode() ?? 'USD';
    }

    /**
     * TND, EUR and USD, the home currency when it is none of them, and the record's own currency when still missing.
     *
     * @return array<string, string>
     */
    public static function currencyOptions(?Invoice $record): array
    {
        return collect([Currency::Tnd, Currency::Eur, Currency::Usd, Currency::tryFrom(self::homeCurrencyCode()), $record?->currency])
            ->filter()
            ->unique(fn (Currency $currency): string => $currency->value)
            ->mapWithKeys(fn (Currency $currency): array => [$currency->value => $currency->getLabel()])
            ->all();
    }

    /**
     * The exchange rate input shared by the form and the "Mark as Sent" modal: home-currency units per 1 unit of the invoice currency.
     *
     * @param  Closure(): string  $prefix
     */
    public static function exchangeRateField(Closure $prefix): TextInput
    {
        return TextInput::make('exchange_rate')
            ->label('Exchange rate')
            ->numeric()
            ->maxValue('9999999999')
            ->rules(['gt:0', 'decimal:0,8'])
            ->prefix($prefix)
            ->suffix(fn (): string => self::homeCurrencyCode())
            ->helperText('Frozen when the invoice is issued.')
            ->validationMessages([
                'required' => 'Enter the exchange rate to issue an invoice in another currency.',
                'numeric' => 'The exchange rate must be a number greater than 0.',
                'gt' => 'The exchange rate must be a number greater than 0.',
                'decimal' => 'The exchange rate can have at most 8 decimal places.',
                'max' => "The exchange rate can't be more than 9,999,999,999.",
            ]);
    }

    private static function currencyCode(mixed $state): string
    {
        if ($state instanceof Currency) {
            return $state->value;
        }

        if (is_string($state) && Currency::tryFrom($state) !== null) {
            return $state;
        }

        return self::homeCurrencyCode();
    }

    private static function issuesInvoice(mixed $status): bool
    {
        $status = $status instanceof InvoiceStatus ? $status : InvoiceStatus::tryFrom((string) (is_scalar($status) ? $status : ''));

        return in_array($status, [InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Partial, InvoiceStatus::Overdue], true);
    }

    private static function isIssued(?Invoice $record): bool
    {
        return $record !== null && $record->status !== InvoiceStatus::Draft;
    }

    /**
     * The statuses a person can pick: Paid and Partial are set by the payments recorded, so they are only offered (and
     * the field locked) when the payments decide the status of the invoice.
     *
     * @return array<string, string>
     */
    private static function statusOptions(?Invoice $record): array
    {
        return collect(InvoiceStatus::cases())
            ->reject(fn (InvoiceStatus $status): bool => ! self::isSetByPayments($record) && in_array($status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true))
            ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    /**
     * Whether the payments recorded decide the status of the invoice: it is Paid or Partial, or it holds money (such as
     * a cancelled invoice not fully refunded), so a person can't move it to a status that contradicts its ledger.
     */
    private static function isSetByPayments(?Invoice $record): bool
    {
        return in_array($record?->status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true)
            || InvoiceLedger::cents($record?->amount_paid) > 0;
    }

    public static function nextInvoiceNumber(): string
    {
        $year = now()->year;

        $lastInvoice = Invoice::query()
            ->where('invoice_number', 'like', sprintf('INV-%s-%%', $year))
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        if (! is_string($lastInvoice) || ! preg_match('/^INV-\d{4}-(\d{3})$/', $lastInvoice, $matches)) {
            return sprintf('INV-%s-001', $year);
        }

        return sprintf('INV-%s-%03d', $year, ((int) $matches[1]) + 1);
    }
}
