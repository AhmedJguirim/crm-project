<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Services\Imports\ImportCellParser;
use App\Support\LocalTime;
use App\Support\TemporaryFile;
use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelWriter;
use ZipArchive;

/**
 * Writes invoices and their payments: in CSV a ZIP holding `invoices.csv` and `payments.csv`, in Excel one file with an
 * `Invoices` and a `Payments` sheet (a CSV file cannot hold two sheets). Ids and related ids come with readable names,
 * timestamps are in the organization timezone (named in their header) and calendar dates are never shifted. Formula
 * safety and number cells are those of {@see ExportCells}.
 */
class InvoiceExportWriter
{
    public const INVOICES_CSV = 'invoices.csv';

    public const PAYMENTS_CSV = 'payments.csv';

    public const INVOICES_SHEET = 'Invoices';

    public const PAYMENTS_SHEET = 'Payments';

    /** The invoice columns in file order; the last two get the timezone appended to their header. */
    public const INVOICE_COLUMNS = [
        'id',
        'number',
        'status',
        'currency',
        'exchange rate',
        'amount',
        'amount paid',
        'payment terms (days)',
        'issued on',
        'due on',
        'paid on',
        'contact id',
        'contact',
        'contact email',
        'deal id',
        'deal',
        'created by',
        'notes',
        'created at',
        'updated at',
    ];

    /** The payment columns in file order; the last one gets the timezone appended to its header. */
    public const PAYMENT_COLUMNS = [
        'id',
        'invoice id',
        'invoice number',
        'kind',
        'amount',
        'currency',
        'paid on',
        'method',
        'reference',
        'recorded by',
        'created at',
    ];

    private const TIMESTAMP_COLUMNS = ['created at', 'updated at'];

    private const TIMESTAMP_FORMAT = 'd-m-Y H:i';

    /**
     * @param  string  $timezone  A valid timezone identifier: the timezone of the organization.
     */
    public function __construct(private readonly string $timezone) {}

    /** @return array<int, string> */
    public function invoiceHeaders(ExportFormat $format = ExportFormat::Xlsx): array
    {
        return $this->headers(self::INVOICE_COLUMNS, $format);
    }

    /** @return array<int, string> */
    public function paymentHeaders(ExportFormat $format = ExportFormat::Xlsx): array
    {
        return $this->headers(self::PAYMENT_COLUMNS, $format);
    }

    /**
     * The cells of one invoice, in the order of `invoiceHeaders()`. The invoice needs its `contact` (a deleted one too),
     * `deal` and `creator` relations loaded.
     *
     * @return array<int, string|int|float>
     */
    public function invoiceRow(Invoice $invoice, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $invoice->id,
            (string) $invoice->invoice_number,
            (string) $invoice->status?->getLabel(),
            (string) $invoice->currency->value,
            $invoice->exchange_rate === null ? '' : ExportCells::number(Invoice::trimExchangeRate((string) $invoice->exchange_rate), $format),
            ExportCells::money($invoice->amount, $format),
            ExportCells::money($invoice->amount_paid, $format),
            $invoice->payment_terms === null ? '' : (int) $invoice->payment_terms,
            (string) $invoice->issued_at?->format(ImportCellParser::DATE_FORMAT),
            (string) $invoice->due_at?->format(ImportCellParser::DATE_FORMAT),
            (string) $invoice->paid_at?->format(ImportCellParser::DATE_FORMAT),
            (int) $invoice->contact_id,
            (string) $invoice->contact?->name,
            (string) $invoice->contact?->email,
            $invoice->deal_id === null ? '' : (int) $invoice->deal_id,
            (string) $invoice->deal?->title,
            (string) $invoice->creator?->email,
            (string) $invoice->notes,
            (string) LocalTime::format($invoice->created_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($invoice->updated_at, self::TIMESTAMP_FORMAT, $this->timezone),
        ], $format);
    }

    /**
     * The cells of one payment or refund, in the order of `paymentHeaders()`. The amount is positive for a refund too:
     * the kind gives the sign. The payment needs its `invoice` and `recordedBy` relations loaded.
     *
     * @return array<int, string|int|float>
     */
    public function paymentRow(InvoicePayment $payment, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $payment->id,
            (int) $payment->invoice_id,
            (string) $payment->invoice?->invoice_number,
            (string) $payment->kind?->getLabel(),
            ExportCells::money($payment->amount, $format),
            (string) $payment->invoice?->currency->value,
            (string) $payment->paid_on?->format(ImportCellParser::DATE_FORMAT),
            (string) $payment->method?->getLabel(),
            (string) $payment->reference,
            (string) $payment->recordedBy?->email,
            (string) LocalTime::format($payment->created_at, self::TIMESTAMP_FORMAT, $this->timezone),
        ], $format);
    }

    /**
     * Writes the invoices, then their payments. The type of the file comes from the format: a ZIP of two CSV files, or
     * an .xlsx with two sheets. The payments are read after the invoices.
     *
     * @param  iterable<int, iterable<int, Invoice>>  $invoiceChunks  Invoices with the relations `invoiceRow()` needs.
     * @param  iterable<int, iterable<int, InvoicePayment>>  $paymentChunks  Payments with the relations `paymentRow()` needs.
     * @return int The number of invoices written.
     */
    public function write(string $path, ExportFormat $format, iterable $invoiceChunks, iterable $paymentChunks): int
    {
        if ($format === ExportFormat::Csv) {
            return $this->writeZip($path, $invoiceChunks, $paymentChunks);
        }

        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();
        $writer->nameCurrentSheet(self::INVOICES_SHEET);

        $count = ExportFileWriter::writeSheet(
            $writer,
            $this->invoiceHeaders($format),
            $invoiceChunks,
            fn (Invoice $invoice): array => $this->invoiceRow($invoice, $format),
        );

        $writer->addNewSheetAndMakeItCurrent(self::PAYMENTS_SHEET);

        ExportFileWriter::writeSheet(
            $writer,
            $this->paymentHeaders($format),
            $paymentChunks,
            fn (InvoicePayment $payment): array => $this->paymentRow($payment, $format),
        );

        $writer->close();

        return $count;
    }

    /**
     * @param  iterable<int, iterable<int, Invoice>>  $invoiceChunks
     * @param  iterable<int, iterable<int, InvoicePayment>>  $paymentChunks
     */
    private function writeZip(string $path, iterable $invoiceChunks, iterable $paymentChunks): int
    {
        $invoicesPath = TemporaryFile::reserve('invoices-export-', 'csv');
        $paymentsPath = TemporaryFile::reserve('payments-export-', 'csv');

        try {
            $count = ExportFileWriter::write(
                $invoicesPath,
                $this->invoiceHeaders(ExportFormat::Csv),
                $invoiceChunks,
                fn (Invoice $invoice): array => $this->invoiceRow($invoice, ExportFormat::Csv),
            );

            ExportFileWriter::write(
                $paymentsPath,
                $this->paymentHeaders(ExportFormat::Csv),
                $paymentChunks,
                fn (InvoicePayment $payment): array => $this->paymentRow($payment, ExportFormat::Csv),
            );

            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not write the invoices ZIP.');
            }

            $zip->addFile($invoicesPath, self::INVOICES_CSV);
            $zip->addFile($paymentsPath, self::PAYMENTS_CSV);

            if ($zip->close() === false) {
                throw new RuntimeException('Could not write the invoices ZIP.');
            }

            return $count;
        } finally {
            @unlink($invoicesPath);
            @unlink($paymentsPath);
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function headers(array $columns, ExportFormat $format): array
    {
        return ExportCells::headers(
            array_map(
                fn (string $column): string => in_array($column, self::TIMESTAMP_COLUMNS, true) ? "{$column} ({$this->timezone})" : $column,
                $columns,
            ),
            $format,
        );
    }
}
