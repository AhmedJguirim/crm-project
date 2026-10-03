<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Tenancy\TenantContext;

class InvoiceObserver
{
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
