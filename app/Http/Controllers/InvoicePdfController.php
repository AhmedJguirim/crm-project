<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfController
{
    public function __invoke(int $invoice): Response
    {
        $invoice = Invoice::withoutGlobalScope('organization')->findOrFail($invoice);

        abort_unless(
            $invoice->organization->hasMember(auth()->user()),
            403,
        );

        return app(TenantContext::class)->run($invoice->organization_id, function () use ($invoice): Response {
            $invoice->load(['contact', 'deal', 'organization']);

            $pdf = Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'organization' => $invoice->organization,
            ])->setPaper('a4');

            return $pdf->download("{$invoice->invoice_number}.pdf");
        });
    }
}
