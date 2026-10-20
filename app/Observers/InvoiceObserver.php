<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\LocalTime;
use App\Support\Tenancy\TenantContext;

class InvoiceObserver
{
    /**
     * Keeps status, amount paid and paid date consistent on every save: a Paid invoice is fully paid, Paid and Partial invoices carry a paid date (today in the organization timezone when empty), any other status has none.
     */
    public function saving(Invoice $invoice): void
    {
        $invoice->amount_paid ??= 0;

        $status = $invoice->status ?? InvoiceStatus::Draft;

        if ($status === InvoiceStatus::Paid) {
            $invoice->amount_paid = $invoice->amount;
        }

        if (in_array($status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true)) {
            $invoice->paid_at ??= LocalTime::todayDate();

            return;
        }

        $invoice->paid_at = null;
    }

    public function creating(Invoice $invoice): void
    {
        if (! $invoice->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $invoice->organization_id = $organizationId;
        }

        if (! $invoice->organization_id && $invoice->contact) {
            $invoice->organization_id = $invoice->contact->organization_id;
        }

        if (! $invoice->status) {
            $invoice->status = InvoiceStatus::Draft;
        }
    }
}
