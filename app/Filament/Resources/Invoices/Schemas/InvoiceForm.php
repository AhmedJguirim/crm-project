<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use Filament\Facades\Filament;
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
                Select::make('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                    )
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('deal_id', null);
                        $set('amount', null);
                        $set('currency', 'USD');
                    })
                    ->rules([
                        fn () => Rule::exists(Contact::class, 'id')->where(
                            fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                        ),
                    ]),

                Select::make('deal_id')
                    ->label('Deal')
                    ->nullable()
                    ->searchable()
                    ->live()
                    ->options(function (Get $get): array {
                        $contactId = $get('contact_id');

                        if (! $contactId) {
                            return [];
                        }

                        return Deal::query()
                            ->where('contact_id', $contactId)
                            ->orderByDesc('created_at')
                            ->get()
                            ->mapWithKeys(fn (Deal $deal): array => [
                                $deal->id => $deal->value
                                    ? "{$deal->title} — ".number_format((float) $deal->value, 2)." {$deal->currency}"
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

                        $set('currency', $deal->currency);
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
                            ->where('organization_id', Filament::getTenant()?->id)
                    ),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(fn (Get $get): string => $get('currency') ?? 'USD'),

                Select::make('currency')
                    ->options([
                        'USD' => 'USD',
                        'EUR' => 'EUR',
                        'GBP' => 'GBP',
                        'CAD' => 'CAD',
                        'AUD' => 'AUD',
                    ])
                    ->default('USD')
                    ->required()
                    ->live(),

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
                    ->hiddenOn('create'),

                TextInput::make('amount_paid')
                    ->label('Amount Paid')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0)
                    ->prefix(fn (Get $get): string => $get('currency') ?? 'USD')
                    ->helperText(fn (Get $get): ?string => self::balanceHelper($get))
                    ->hiddenOn('create'),

                DatePicker::make('issued_at')
                    ->label('Issued')
                    ->default(today())
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
                    ->default(fn (): string => today()->addDays(30)->format('Y-m-d')),

                DatePicker::make('paid_at')
                    ->label('Paid')
                    ->visible(function (Get $get): bool {
                        $status = $get('status');

                        if ($status instanceof InvoiceStatus) {
                            return in_array($status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true);
                        }

                        return in_array($status, [InvoiceStatus::Paid->value, InvoiceStatus::Partial->value], true);
                    }),

                Textarea::make('notes')
                    ->rows(4)
                    ->nullable()
                    ->columnSpanFull(),
            ]);
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
            $currency = $get('currency') ?? 'USD';

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
