<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Facades\Filament;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (! $invoice->organization_id && Filament::getTenant()) {
            $invoice->organization_id = Filament::getTenant()->id;
        }

        if (! $invoice->organization_id && $invoice->contact) {
            $invoice->organization_id = $invoice->contact->organization_id;
        }

        if (! $invoice->status) {
            $invoice->status = InvoiceStatus::Draft;
        }
    }
}
