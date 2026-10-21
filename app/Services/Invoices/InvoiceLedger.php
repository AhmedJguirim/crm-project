<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoicePaymentRefused;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Records the payments and refunds of an invoice in its append-only ledger and derives the amount paid, the paid date
 * and the Paid / Partial status of the invoice from it.
 */
class InvoiceLedger
{
    /**
     * Appends one entry to the ledger of the invoice. The invoice row is locked and the rules are checked again on the
     * locked row, so two entries recorded at the same time can never take the amount paid above the invoice amount or
     * below zero.
     *
     * @param  string  $amount  A positive amount with at most two decimals.
     * @param  string  $paidOn  A `Y-m-d` date.
     *
     * @throws InvoicePaymentRefused
     */
    public function record(
        Invoice $invoice,
        InvoicePaymentKind $kind,
        string $amount,
        string $paidOn,
        ?PaymentMethod $method,
        ?string $reference,
        ?User $recordedBy,
    ): InvoicePayment {
        return DB::transaction(function () use ($invoice, $kind, $amount, $paidOn, $method, $reference, $recordedBy): InvoicePayment {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $this->refuseWhenBroken($locked, $kind, $amount);

            $entry = $locked->payments()->create([
                'organization_id' => $locked->organization_id,
                'kind' => $kind,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'recorded_by_id' => $recordedBy?->getKey(),
            ]);

            $this->derive($locked, $kind, $paidOn);

            $invoice->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

    /**
     * @throws InvoicePaymentRefused
     */
    private function refuseWhenBroken(Invoice $invoice, InvoicePaymentKind $kind, string $amount): void
    {
        $cents = self::cents($amount);
        $paid = self::cents($invoice->amount_paid);

        if ($kind === InvoicePaymentKind::Payment) {
            if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true)) {
                throw new InvoicePaymentRefused('This invoice no longer accepts payments.');
            }

            $balance = self::cents($invoice->amount) - $paid;

            if ($cents <= 0 || $cents > $balance) {
                throw new InvoicePaymentRefused("The payment can't be more than the balance due (".self::money($balance).').');
            }

            return;
        }

        if ($paid <= 0) {
            throw new InvoicePaymentRefused('There is nothing to refund on this invoice.');
        }

        if ($cents <= 0 || $cents > $paid) {
            throw new InvoicePaymentRefused("The refund can't be more than the amount paid (".self::money($paid).').');
        }
    }

    /**
     * Sets the amount paid from the ledger, then the status and the paid date: Paid when the amount is covered,
     * Partial while something is paid, Sent when nothing is left. A cancelled invoice keeps its status and only its
     * amount paid changes.
     */
    private function derive(Invoice $invoice, InvoicePaymentKind $kind, string $paidOn): void
    {
        $paid = self::cents($invoice->payments()->where('kind', InvoicePaymentKind::Payment->value)->sum('amount'))
            - self::cents($invoice->payments()->where('kind', InvoicePaymentKind::Refund->value)->sum('amount'));

        $invoice->amount_paid = number_format($paid / 100, 2, '.', '');

        if ($invoice->status !== InvoiceStatus::Cancelled) {
            if ($paid >= self::cents($invoice->amount)) {
                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = $paidOn;
            } elseif ($paid > 0) {
                $invoice->status = InvoiceStatus::Partial;

                if ($kind === InvoicePaymentKind::Payment) {
                    $invoice->paid_at = $paidOn;
                }
            } else {
                $invoice->status = InvoiceStatus::Sent;
                $invoice->paid_at = null;
            }
        }

        $invoice->save();
    }

    /**
     * A money value as integer cents.
     */
    public static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function money(int $cents): string
    {
        return (string) Number::currency($cents / 100, CurrentOrganization::find()?->currencyCode() ?? 'USD');
    }
}
