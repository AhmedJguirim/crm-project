<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceLedger;
use App\Support\LocalTime;
use App\Support\MoneyLimit;
use App\Support\Tenancy\CurrentOrganization;
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
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (! $state) {
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

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(MoneyLimit::MAX)
                    ->step(0.01)
                    ->prefix(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                    ->disabled(fn (?Invoice $record): bool => $record?->payments()->exists() ?? false)
                    ->helperText(fn (?Invoice $record): ?string => $record?->payments()->exists()
                        ? "The amount can't change once a payment is recorded."
                        : null),

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
