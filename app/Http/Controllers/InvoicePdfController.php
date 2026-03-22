<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfController
{
    public function __invoke(int $invoice): Response
    {
        $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoice);

        $invoice->load(['contact', 'deal', 'organization']);

        abort_unless(
            $invoice->organization->hasMember(auth()->user()),
            403,
        );

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'organization' => $invoice->organization,
        ])->setPaper('a4');

        $filename = "{$invoice->invoice_number}.pdf";

        return $pdf->download($filename);
    }
}
