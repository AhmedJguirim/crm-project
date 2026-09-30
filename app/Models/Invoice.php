<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\InvoiceObserver;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'payment_terms',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'payment_terms' => 'integer',
            'status' => InvoiceStatus::class,
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
                        ->whereDate('due_at', '<', today());
                });
        });
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }
}
