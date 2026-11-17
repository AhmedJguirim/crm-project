<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Services\InvoiceExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given invoices and their payments and refunds to a file (a ZIP of two CSV files, or an .xlsx with two
 * sheets) and tells the user when it is ready to download.
 *
 * It runs on the `exports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the invoices 500 at a time, in the order of the given ids.
 */
class ExportInvoicesJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $invoiceIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $invoiceIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'invoices';
    }

    protected function exportNoun(): string
    {
        return 'invoice';
    }

    protected function exportFileExtension(ExportFormat $format): string
    {
        return $format === ExportFormat::Csv ? 'zip' : $format->value;
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $organization = Organization::find($this->organizationId);

        $writer = new InvoiceExportWriter(Organization::validTimezoneOrUtc($organization?->timezone));

        return $writer->write($temporaryPath, $format, $this->invoiceChunks(), $this->paymentChunks());
    }

    /**
     * The invoices of the export, one chunk at a time, in the order of the ids. An invoice that no longer exists, or
     * that belongs to another organization, is skipped.
     *
     * @return Generator<int, Collection<int, Invoice>>
     */
    private function invoiceChunks(): Generator
    {
        $positions = array_flip($this->invoiceIds);

        foreach (array_chunk($this->invoiceIds, self::CHUNK_SIZE) as $ids) {
            yield Invoice::query()
                ->forOrganization($this->organizationId)
                ->with([
                    'contact' => fn (BelongsTo $query) => $query->withTrashed(),
                    'deal',
                    'creator',
                ])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Invoice $invoice): int => $positions[$invoice->getKey()])
                ->values();
        }
    }

    /**
     * The payments and refunds of the same invoices, one chunk of invoices at a time: by the position of their
     * invoice in the export, then by date, then by id.
     *
     * @return Generator<int, Collection<int, InvoicePayment>>
     */
    private function paymentChunks(): Generator
    {
        $positions = array_flip($this->invoiceIds);

        foreach (array_chunk($this->invoiceIds, self::CHUNK_SIZE) as $ids) {
            yield InvoicePayment::query()
                ->forOrganization($this->organizationId)
                ->with(['invoice', 'recordedBy'])
                ->whereIn('invoice_id', $ids)
                ->get()
                ->sort(fn (InvoicePayment $a, InvoicePayment $b): int => [
                    $positions[$a->invoice_id], $a->paid_on->getTimestamp(), $a->getKey(),
                ] <=> [
                    $positions[$b->invoice_id], $b->paid_on->getTimestamp(), $b->getKey(),
                ])
                ->values();
        }
    }
}
