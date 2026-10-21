<?php

namespace App\Models;

use App\Enums\InvoicePaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\InvoicePaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One dated entry of the payment ledger of an invoice: a payment or a refund, with a positive amount (the kind gives
 * the sign). Entries are append-only: a wrong payment is corrected by recording a refund, never by editing or
 * deleting it.
 */
class InvoicePayment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoicePaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'kind',
        'amount',
        'paid_on',
        'method',
        'reference',
        'recorded_by_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Invoice payments are append-only: record a refund instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Invoice payments are append-only: record a refund instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => InvoicePaymentKind::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
