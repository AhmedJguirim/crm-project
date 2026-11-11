<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\InvoiceObserver;
use App\Support\LocalTime;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

#[ObservedBy([InvoiceObserver::class])]
class Invoice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'deal_id',
        'invoice_number',
        'amount',
        'amount_paid',
        'currency',
        'exchange_rate',
        'payment_terms',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'currency' => Currency::class,
            'exchange_rate' => 'decimal:8',
            'payment_terms' => 'integer',
            'status' => InvoiceStatus::class,
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The user who created the invoice (null for older rows or when that user was deleted).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * The payment ledger of the invoice (callers order it).
     *
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNotNull('paid_at');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Sent->value,
            InvoiceStatus::Partial->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->where('status', InvoiceStatus::Overdue->value)
                ->orWhere(function (Builder $innerBuilder): void {
                    $innerBuilder
                        ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Partial->value])
                        ->where('due_at', '<', LocalTime::todayDate());
                });
        });
    }

    /**
     * An amount of this invoice in its own currency, always with 2 decimals.
     */
    public function formatMoney(float|int|string|null $amount): string
    {
        return (string) Number::currency((float) $amount, $this->currency->value, precision: 2);
    }

    /**
     * An exchange rate without its trailing decimal zeros (`3.40000000` becomes `3.4`, `10.00000000` becomes `10`).
     */
    public static function trimExchangeRate(?string $rate): ?string
    {
        if ($rate === null || ! str_contains($rate, '.')) {
            return $rate;
        }

        return rtrim(rtrim($rate, '0'), '.');
    }

    /**
     * The rate of the most recently issued invoice of the current organization in that currency, trimmed, or null.
     */
    public static function latestExchangeRate(Currency $currency): ?string
    {
        $rate = self::query()
            ->where('currency', $currency->value)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereNotNull('exchange_rate')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->value('exchange_rate');

        return self::trimExchangeRate($rate === null ? null : (string) $rate);
    }

    /**
     * An unpaid invoice whose due date is strictly before today in the organization timezone (an invoice due today is
     * not past due yet).
     */
    public function isPastDue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true)
            && $this->due_at !== null
            && $this->due_at->toDateString() < LocalTime::todayDate();
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereBetween('issued_at', LocalTime::periodDates('month'));
    }
}
