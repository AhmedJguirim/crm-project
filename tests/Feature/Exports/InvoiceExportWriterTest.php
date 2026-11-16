<?php

use App\Enums\Currency;
use App\Enums\ExportFormat;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Exports\ExportCells;
use App\Services\Imports\TextPreservingExcelReader;
use App\Services\InvoiceExportWriter;
use App\Support\TemporaryFile;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'owner@example.com']);
    $this->org = $this->user->personalOrganization();
    $this->org->forceFill(['currency' => 'TND', 'timezone' => 'Pacific/Auckland'])->saveQuietly();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->writer = fn (string $timezone = 'Pacific/Auckland'): InvoiceExportWriter => new InvoiceExportWriter($timezone);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Hélène Durand', 'email' => 'helene@example.com']);
    $this->deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'title' => 'Website redesign']);

    $this->invoiceA = Invoice::factory()->inCurrency(Currency::Eur, '3.40000000')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'deal_id' => $this->deal->id,
        'invoice_number' => 'INV-2026-0001',
        'status' => InvoiceStatus::Partial,
        'amount' => '1200.50',
        'amount_paid' => '200.00',
        'payment_terms' => 30,
        'issued_at' => '2026-11-01',
        'due_at' => '2026-12-01',
        'paid_at' => '2026-11-07',
        'notes' => 'Thanks',
        'created_by' => $this->user->id,
        'created_at' => '2026-11-10 22:30:00',
    ]);

    $this->invoiceB = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-2026-0002',
        'status' => InvoiceStatus::Draft,
        'currency' => Currency::Tnd,
        'amount' => '50.00',
        'amount_paid' => '0.00',
        'payment_terms' => 0,
        'paid_at' => null,
        'notes' => null,
        'created_by' => null,
    ]);

    $this->payment = InvoicePayment::factory()->create([
        'organization_id' => $this->org->id,
        'invoice_id' => $this->invoiceA->id,
        'kind' => InvoicePaymentKind::Payment,
        'amount' => '300.00',
        'paid_on' => '2026-11-05',
        'method' => PaymentMethod::BankTransfer,
        'reference' => 'VIR-881',
        'recorded_by_id' => $this->user->id,
    ]);

    $this->refund = InvoicePayment::factory()->refund()->create([
        'organization_id' => $this->org->id,
        'invoice_id' => $this->invoiceA->id,
        'amount' => '100.00',
        'paid_on' => '2026-11-07',
        'method' => PaymentMethod::Card,
        'reference' => null,
        'recorded_by_id' => null,
    ]);

    $this->loadInvoice = fn (Invoice $invoice): Invoice => Invoice::query()
        ->with(['contact' => fn (BelongsTo $query) => $query->withTrashed(), 'deal', 'creator'])
        ->findOrFail($invoice->id);

    $this->loadPayment = fn (InvoicePayment $payment): InvoicePayment => InvoicePayment::query()->with(['invoice', 'recordedBy'])->findOrFail($payment->id);

    $this->invoices = fn (array $invoices): array => [array_map($this->loadInvoice, $invoices)];
    $this->payments = fn (array $payments): array => [array_map($this->loadPayment, $payments)];

    $this->zipRows = function (string $zipPath, string $entry): array {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $temporary = tempnam(sys_get_temp_dir(), 'zip-entry').'.csv';
        file_put_contents($temporary, $zip->getFromName($entry));
        $zip->close();

        $rows = SimpleExcelReader::create($temporary, 'csv')->getRows()->all();
        unlink($temporary);

        return $rows;
    };
});

it('names the invoice and payment columns, the timezone on the timestamps only', function () {
    expect(($this->writer)()->invoiceHeaders())->toBe([
        'id', 'number', 'status', 'currency', 'exchange rate', 'amount', 'amount paid', 'payment terms (days)', 'issued on', 'due on', 'paid on',
        'contact id', 'contact', 'contact email', 'deal id', 'deal', 'created by', 'notes',
        'created at (Pacific/Auckland)', 'updated at (Pacific/Auckland)',
    ])->and(($this->writer)()->paymentHeaders())->toBe([
        'id', 'invoice id', 'invoice number', 'kind', 'amount', 'currency', 'paid on', 'method', 'reference', 'recorded by', 'created at (Pacific/Auckland)',
    ])->and(InvoiceExportWriter::INVOICE_COLUMNS)->toHaveCount(20)
        ->and(InvoiceExportWriter::PAYMENT_COLUMNS)->toHaveCount(11);
});

it('keeps the header text safe in a CSV', function () {
    expect(($this->writer)()->invoiceHeaders(ExportFormat::Csv))->toBe(ExportCells::headers(($this->writer)()->invoiceHeaders(), ExportFormat::Csv));
});

it('writes an invoice row in a CSV', function () {
    $row = ($this->writer)()->invoiceRow(($this->loadInvoice)($this->invoiceA), ExportFormat::Csv);

    expect($row)->toBe([
        $this->invoiceA->id, 'INV-2026-0001', 'Partial', 'EUR', '3.4', '1200.50', '200.00', 30, '01-11-2026', '01-12-2026', '07-11-2026',
        $this->contact->id, 'Hélène Durand', 'helene@example.com', $this->deal->id, 'Website redesign', 'owner@example.com', 'Thanks',
        '11-11-2026 11:30', $row[19],
    ]);
});

it('writes an invoice without exchange rate, deal or creator as empty cells', function () {
    Invoice::query()->whereKey($this->invoiceB->id)->toBase()->update(['created_by' => null]);

    $row = ($this->writer)()->invoiceRow(($this->loadInvoice)($this->invoiceB), ExportFormat::Csv);

    expect($row[1])->toBe('INV-2026-0002')
        ->and($row[2])->toBe('Draft')
        ->and($row[3])->toBe('TND')
        ->and($row[4])->toBe('')
        ->and($row[5])->toBe('50.00')
        ->and($row[7])->toBe(0)
        ->and($row[10])->toBe('')
        ->and($row[14])->toBe('')
        ->and($row[15])->toBe('')
        ->and($row[16])->toBe('')
        ->and($row[17])->toBe('');
});

it('writes an empty cell for a missing payment term', function () {
    $invoice = ($this->loadInvoice)($this->invoiceA);
    $invoice->payment_terms = null;

    expect(($this->writer)()->invoiceRow($invoice, ExportFormat::Csv)[7])->toBe('');
});

it('writes the money and the rate as numbers in an xlsx', function () {
    $row = ($this->writer)()->invoiceRow(($this->loadInvoice)($this->invoiceA), ExportFormat::Xlsx);

    expect($row[4])->toBe(3.4)
        ->and($row[5])->toBe(1200.5)
        ->and($row[6])->toBe(200.0);
});

it('never shifts the calendar dates into the timezone of the organization', function () {
    $invoice = ($this->loadInvoice)($this->invoiceA);

    foreach (['Pacific/Auckland', 'America/Los_Angeles', 'UTC'] as $timezone) {
        $row = ($this->writer)($timezone)->invoiceRow($invoice, ExportFormat::Csv);

        expect([$row[8], $row[9], $row[10]])->toBe(['01-11-2026', '01-12-2026', '07-11-2026']);
    }

    $payment = ($this->writer)('America/Los_Angeles')->paymentRow(($this->loadPayment)($this->payment), ExportFormat::Csv);

    expect($payment[6])->toBe('05-11-2026');
});

it('writes the timestamps in the timezone of the organization', function () {
    $invoice = ($this->loadInvoice)($this->invoiceA);

    expect(($this->writer)('Pacific/Auckland')->invoiceRow($invoice, ExportFormat::Csv)[18])->toBe('11-11-2026 11:30')
        ->and(($this->writer)('UTC')->invoiceRow($invoice, ExportFormat::Csv)[18])->toBe('10-11-2026 22:30');
});

it('writes the payment and the refund rows with a positive amount and the currency of the invoice', function () {
    $payment = ($this->writer)()->paymentRow(($this->loadPayment)($this->payment), ExportFormat::Csv);
    $refund = ($this->writer)()->paymentRow(($this->loadPayment)($this->refund), ExportFormat::Csv);

    expect(array_slice($payment, 0, 10))->toBe([$this->payment->id, $this->invoiceA->id, 'INV-2026-0001', 'Payment', '300.00', 'EUR', '05-11-2026', 'Bank transfer', 'VIR-881', 'owner@example.com'])
        ->and(array_slice($refund, 0, 10))->toBe([$this->refund->id, $this->invoiceA->id, 'INV-2026-0001', 'Refund', '100.00', 'EUR', '07-11-2026', 'Card', '', '']);
});

it('writes a payment without a method as an empty cell', function () {
    $this->payment->forceFill(['method' => null])->saveQuietly();

    expect(($this->writer)()->paymentRow(($this->loadPayment)($this->payment), ExportFormat::Csv)[7])->toBe('');
});

it('keeps the contact of a soft-deleted contact', function () {
    $this->contact->delete();

    $row = ($this->writer)()->invoiceRow(($this->loadInvoice)($this->invoiceA), ExportFormat::Csv);

    expect($row[12])->toBe('Hélène Durand')
        ->and($row[13])->toBe('helene@example.com');
});

it('neutralises a formula in the notes and in the payment reference in a CSV inside the ZIP', function (string $value) {
    $this->invoiceA->forceFill(['notes' => $value])->saveQuietly();
    InvoicePayment::query()->whereKey($this->payment->id)->toBase()->update(['reference' => $value]);
    $zipPath = tempnam(sys_get_temp_dir(), 'invoice-export').'.zip';

    ($this->writer)()->write($zipPath, ExportFormat::Csv, ($this->invoices)([$this->invoiceA]), ($this->payments)([$this->payment]));

    $invoices = ($this->zipRows)($zipPath, 'invoices.csv');
    $payments = ($this->zipRows)($zipPath, 'payments.csv');
    unlink($zipPath);

    expect($invoices[0]['notes'])->toBe("'{$value}")
        ->and($payments[0]['reference'])->toBe("'{$value}");
})->with(['=HYPERLINK("x","y")', '@SUM(1)', '+1+1', '-2+3']);

it('keeps a formula as text in an xlsx', function () {
    $this->invoiceA->forceFill(['notes' => '=1+1'])->saveQuietly();
    $path = tempnam(sys_get_temp_dir(), 'invoice-export').'.xlsx';

    ($this->writer)()->write($path, ExportFormat::Xlsx, ($this->invoices)([$this->invoiceA]), ($this->payments)([$this->payment]));
    $rows = TextPreservingExcelReader::create($path, 'xlsx')->fromSheetName('Invoices')->getRows()->all();
    unlink($path);

    expect($rows[0]['notes'])->toBe('=1+1');
});

it('writes a ZIP of exactly invoices.csv and payments.csv', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'invoice-export').'.zip';

    $count = ($this->writer)()->write($zipPath, ExportFormat::Csv, ($this->invoices)([$this->invoiceA, $this->invoiceB]), ($this->payments)([$this->payment, $this->refund]));

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    foreach (range(0, $zip->numFiles - 1) as $index) {
        $names[] = $zip->getNameIndex($index);
    }
    $zip->close();
    $invoices = ($this->zipRows)($zipPath, InvoiceExportWriter::INVOICES_CSV);
    $payments = ($this->zipRows)($zipPath, InvoiceExportWriter::PAYMENTS_CSV);
    unlink($zipPath);

    expect($count)->toBe(2)
        ->and($names)->toBe(['invoices.csv', 'payments.csv'])
        ->and(array_column($invoices, 'number'))->toBe(['INV-2026-0001', 'INV-2026-0002'])
        ->and(array_column($invoices, 'exchange rate'))->toBe(['3.4', ''])
        ->and(array_column($payments, 'kind'))->toBe(['Payment', 'Refund'])
        ->and(array_column($payments, 'amount'))->toBe(['300.00', '100.00']);
});

it('keeps both header rows when there is nothing to write, in the ZIP and in the xlsx', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'invoice-export').'.zip';
    $xlsxPath = tempnam(sys_get_temp_dir(), 'invoice-export').'.xlsx';

    $zipCount = ($this->writer)()->write($zipPath, ExportFormat::Csv, [], []);
    $xlsxCount = ($this->writer)()->write($xlsxPath, ExportFormat::Xlsx, [], []);

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $invoiceLines = array_filter(explode("\n", (string) $zip->getFromName('invoices.csv')));
    $paymentLines = array_filter(explode("\n", (string) $zip->getFromName('payments.csv')));
    $zip->close();
    $invoiceHeader = SimpleExcelReader::create($xlsxPath, 'xlsx')->noHeaderRow()->fromSheetName('Invoices')->getRows()->first();
    $paymentHeader = SimpleExcelReader::create($xlsxPath, 'xlsx')->noHeaderRow()->fromSheetName('Payments')->getRows()->first();
    unlink($zipPath);
    unlink($xlsxPath);

    expect($zipCount)->toBe(0)
        ->and($xlsxCount)->toBe(0)
        ->and($invoiceLines)->toHaveCount(1)
        ->and($paymentLines)->toHaveCount(1)
        ->and($invoiceHeader)->toHaveCount(20)
        ->and($paymentHeader)->toHaveCount(11);
});

it('writes an xlsx with an Invoices sheet and a Payments sheet, in that order', function () {
    $path = tempnam(sys_get_temp_dir(), 'invoice-export').'.xlsx';

    $count = ($this->writer)()->write($path, ExportFormat::Xlsx, ($this->invoices)([$this->invoiceA, $this->invoiceB]), ($this->payments)([$this->payment, $this->refund]));

    $names = SimpleExcelReader::create($path, 'xlsx')->getSheetNames();
    $invoices = TextPreservingExcelReader::create($path, 'xlsx')->fromSheetName('Invoices')->getRows()->all();
    $payments = TextPreservingExcelReader::create($path, 'xlsx')->fromSheetName('Payments')->getRows()->all();
    unlink($path);

    expect($count)->toBe(2)
        ->and(array_values($names))->toBe(['Invoices', 'Payments'])
        ->and(array_column($invoices, 'number'))->toBe(['INV-2026-0001', 'INV-2026-0002'])
        ->and($invoices[0]['amount'])->toBe(1200.5)
        ->and($invoices[0]['exchange rate'])->toBe(3.4)
        ->and(array_column($payments, 'invoice number'))->toBe(['INV-2026-0001', 'INV-2026-0001'])
        ->and(array_column($payments, 'kind'))->toBe(['Payment', 'Refund']);
});

it('removes its temporary CSV files, also when writing fails', function () {
    $directory = sys_get_temp_dir().'/crm-invoice-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);
    $zipPath = TemporaryFile::reserve('invoices-zip-', 'zip');

    ($this->writer)()->write($zipPath, ExportFormat::Csv, ($this->invoices)([$this->invoiceA]), ($this->payments)([$this->payment]));
    $afterSuccess = glob("{$directory}/*");

    $failing = (function () {
        yield [($this->loadInvoice)($this->invoiceA)];

        throw new RuntimeException('boom');
    })();

    try {
        ($this->writer)()->write($zipPath, ExportFormat::Csv, $failing, []);
    } catch (RuntimeException) {
    }

    $afterFailure = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    array_map('unlink', glob("{$directory}/*"));
    rmdir($directory);

    expect($afterSuccess)->toBe([$zipPath])
        ->and($afterFailure)->toBe([$zipPath]);
});

it('fails with a clear error when the ZIP cannot be written', function () {
    expect(fn () => ($this->writer)()->write(sys_get_temp_dir(), ExportFormat::Csv, [], []))
        ->toThrow(RuntimeException::class, 'Could not write the invoices ZIP.');
});
