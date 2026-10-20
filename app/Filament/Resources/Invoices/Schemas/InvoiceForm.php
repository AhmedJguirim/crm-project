<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
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
                    ->prefix(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD'),

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
                    ->options(InvoiceStatus::class)
                    ->default(InvoiceStatus::Draft)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        if (self::statusOf($get) === InvoiceStatus::Paid) {
                            $set('amount_paid', $get('amount'));
                        }
                    })
                    ->hiddenOn('create'),

                TextInput::make('amount_paid')
                    ->label('Amount Paid')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(MoneyLimit::MAX)
                    ->step(0.01)
                    ->default(0)
                    ->disabled(fn (Get $get): bool => self::statusOf($get) === InvoiceStatus::Paid)
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if ($value !== null && ! is_numeric($value)) {
                            return;
                        }

                        $paid = round((float) ($value ?? 0), 2);
                        $amount = round((float) ($get('amount') ?? 0), 2);

                        match (self::statusOf($get)) {
                            InvoiceStatus::Partial => match (true) {
                                $paid <= 0 => $fail('A partial invoice needs the amount already paid.'),
                                $paid >= $amount => $fail('A partial payment must be less than the invoice amount. Set the status to Paid if the invoice is fully paid.'),
                                default => null,
                            },
                            InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Overdue => $paid > 0
                                ? $fail('Only a partial or paid invoice can have an amount paid. Set the status to Partial, or the amount paid to 0.')
                                : null,
                            default => null,
                        };
                    })
                    ->prefix(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                    ->helperText(fn (Get $get): ?string => self::balanceHelper($get))
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

                DatePicker::make('paid_at')
                    ->label('Paid')
                    ->visible(fn (Get $get): bool => in_array(self::statusOf($get), [InvoiceStatus::Paid, InvoiceStatus::Partial], true))
                    ->helperText(fn (Get $get): ?string => match (self::statusOf($get)) {
                        InvoiceStatus::Paid => 'The date the invoice was fully paid. Today if left empty.',
                        InvoiceStatus::Partial => 'The date of the last payment received. Today if left empty.',
                        default => null,
                    }),

                Textarea::make('notes')
                    ->rows(4)
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }

    private static function statusOf(Get $get): ?InvoiceStatus
    {
        $status = $get('status');

        if ($status instanceof InvoiceStatus) {
            return $status;
        }

        return is_string($status) ? InvoiceStatus::tryFrom($status) : null;
    }

    private static function balanceHelper(Get $get): ?string
    {
        $amount = (float) ($get('amount') ?? 0);
        $paid = (float) ($get('amount_paid') ?? 0);
        $balance = $amount - $paid;

        if ($balance <= 0 && $amount > 0) {
            return 'Fully paid';
        }

        if ($paid > 0) {
            $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

            return 'Balance remaining: '.number_format($balance, 2)." {$currency}";
        }

        return null;
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
