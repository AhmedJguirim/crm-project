<?php

use App\Enums\Currency;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Jobs\ExportInvoicesJob;
use App\Jobs\Middleware\ReleasesImportExportSlot;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Support\TemporaryFile;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->invoice = fn (string $number, array $attributes = [], ?int $organizationId = null): Invoice => Invoice::factory()->create([
        'organization_id' => $organizationId ?? $this->org->id,
        'contact_id' => $organizationId === null ? $this->contact->id : Contact::factory()->create(['organization_id' => $organizationId])->id,
        'invoice_number' => $number,
        'status' => InvoiceStatus::Sent,
        'amount' => '100.00',
        'amount_paid' => '0.00',
        'paid_at' => null,
        ...$attributes,
    ]);

    $this->payment = fn (Invoice $invoice, string $paidOn, InvoicePaymentKind $kind = InvoicePaymentKind::Payment, string $reference = ''): InvoicePayment => InvoicePayment::factory()->create([
        'organization_id' => $invoice->organization_id,
        'invoice_id' => $invoice->id,
        'kind' => $kind,
        'amount' => '10.00',
        'paid_on' => $paidOn,
        'method' => PaymentMethod::Card,
        'reference' => $reference,
    ]);

    $this->run = function (array $ids, string $format = 'csv', ?int $organizationId = null): void {
        DatabaseNotification::query()->delete();
        Storage::disk('local')->deleteDirectory('exports');
        $extension = $format === 'csv' ? 'zip' : $format;
        ExportInvoicesJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "invoices-2026-11-16.{$extension}");
    };

    $this->storedPath = fn (): string => Storage::disk('local')->path(Storage::disk('local')->files('exports')[0]);

    $this->zipRows = function (string $entry): array {
        $zip = new ZipArchive;
        $zip->open(($this->storedPath)());
        $temporary = tempnam(sys_get_temp_dir(), 'zip-entry').'.csv';
        file_put_contents($temporary, $zip->getFromName($entry));
        $zip->close();

        $rows = SimpleExcelReader::create($temporary, 'csv')->getRows()->all();
        unlink($temporary);

        return $rows;
    };

    $this->sheetRows = fn (string $sheet): array => SimpleExcelReader::create(($this->storedPath)(), 'xlsx')->fromSheetName($sheet)->getRows()->all();

    $this->a = ($this->invoice)('INV-A');
    $this->b = ($this->invoice)('INV-B');
    ($this->payment)($this->a, '2026-11-07', InvoicePaymentKind::Refund);
    ($this->payment)($this->a, '2026-11-05');
});

it('gives a ZIP of exactly two CSV files and tells the user with a signed link', function () {
    ($this->run)([$this->a->id, $this->b->id]);

    $file = basename(($this->storedPath)());
    $zip = new ZipArchive;
    $zip->open(($this->storedPath)());
    $entries = [];
    foreach (range(0, $zip->numFiles - 1) as $index) {
        $entries[] = $zip->getNameIndex($index);
    }
    $zip->close();

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];

    expect(Storage::disk('local')->files('exports'))->toHaveCount(1)
        ->and($file)->toMatch('/^invoices-o'.$this->org->id.'-u'.$this->user->id.'-[A-Za-z0-9]{40}\.zip$/')
        ->and($entries)->toBe(['invoices.csv', 'payments.csv'])
        ->and(array_column(($this->zipRows)('invoices.csv'), 'number'))->toBe(['INV-A', 'INV-B'])
        ->and(($this->zipRows)('payments.csv'))->toHaveCount(2)
        ->and($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('2 invoices')
        ->and($url)->toContain('name=invoices-2026-11-16.zip');

    $this->actingAs($this->user)->get($url)
        ->assertOk()
        ->assertDownload('invoices-2026-11-16.zip')
        ->assertHeader('Content-Type', 'application/zip');
});

it('gives one xlsx with an Invoices and a Payments sheet', function () {
    ($this->run)([$this->b->id, $this->a->id], 'xlsx');

    $file = basename(($this->storedPath)());
    $names = SimpleExcelReader::create(($this->storedPath)(), 'xlsx')->getSheetNames();

    expect($file)->toEndWith('.xlsx')
        ->and(array_values($names))->toBe(['Invoices', 'Payments'])
        ->and(array_column(($this->sheetRows)('Invoices'), 'number'))->toBe(['INV-B', 'INV-A'])
        ->and(array_column(($this->sheetRows)('Payments'), 'invoice number'))->toBe(['INV-A', 'INV-A'])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('2 invoices');
});

it('says "1 invoice" and keeps only the header in payments.csv when there is no payment', function () {
    ($this->run)([$this->b->id]);

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 invoice')
        ->and(($this->zipRows)('payments.csv'))->toBe([]);
});

it('writes the payments by invoice position, then date, then id', function () {
    $c = ($this->invoice)('INV-C');
    ($this->payment)($c, '2026-11-01', reference: 'c-first');
    ($this->payment)($this->b, '2026-11-02', reference: 'b-only');
    ($this->payment)($this->a, '2026-11-05', reference: 'a-same-day-later-id');

    ($this->run)([$c->id, $this->a->id, $this->b->id]);

    expect(array_column(($this->zipRows)('payments.csv'), 'reference'))->toBe(['c-first', '', 'a-same-day-later-id', '', 'b-only'])
        ->and(array_column(($this->zipRows)('payments.csv'), 'paid on'))->toBe(['01-11-2026', '05-11-2026', '05-11-2026', '07-11-2026', '02-11-2026']);
});

it('writes the invoices in the order of the given ids and skips an id that is gone', function () {
    $gone = ($this->invoice)('INV-GONE');
    $goneId = $gone->id;
    $gone->delete();

    ($this->run)([$this->b->id, $goneId, $this->a->id]);

    expect(array_column(($this->zipRows)('invoices.csv'), 'number'))->toBe(['INV-B', 'INV-A'])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('2 invoices');
});

it('reads the invoices and payments in chunks of 500 and keeps the order across chunks', function () {
    $ids = [];
    $first = null;

    foreach (range(1, 501) as $number) {
        $invoice = ($this->invoice)(sprintf('INV-C%03d', $number));
        $first ??= $invoice;
        $last = $invoice;
        $ids[] = $invoice->id;
    }

    ($this->payment)($first, '2026-11-01', reference: 'first-chunk');
    ($this->payment)($last, '2026-11-01', reference: 'second-chunk');

    ($this->run)(array_reverse($ids));

    $invoices = ($this->zipRows)('invoices.csv');
    $payments = ($this->zipRows)('payments.csv');

    expect($invoices)->toHaveCount(501)
        ->and($invoices[0]['number'])->toBe('INV-C501')
        ->and($invoices[500]['number'])->toBe('INV-C001')
        ->and(array_column($payments, 'reference'))->toBe(['second-chunk', 'first-chunk']);
});

it('exports only invoices and payments of its organization, whatever ids it is given', function () {
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $globex = ($this->invoice)('INV-GLOBEX', [], $other->id);
    ($this->payment)($globex, '2026-11-03', reference: 'globex secret');

    ($this->run)([$this->a->id, $globex->id]);

    expect(array_column(($this->zipRows)('invoices.csv'), 'number'))->toBe(['INV-A'])
        ->and(array_column(($this->zipRows)('payments.csv'), 'invoice number'))->toBe(['INV-A', 'INV-A'])
        ->and(array_column(($this->zipRows)('payments.csv'), 'reference'))->not->toContain('globex secret');
});

it('filters by its own organization even when another tenant is the one in use', function () {
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $globex = ($this->invoice)('INV-GLOBEX', [], $other->id);
    ($this->payment)($globex, '2026-11-03', reference: 'globex secret');
    $other->members()->attach($this->user->id, ['role' => OrganizationRole::Admin->value]);
    $this->actingAs($this->user);
    Filament::setTenant($other);

    ($this->run)([$this->a->id, $globex->id]);

    expect(array_column(($this->zipRows)('invoices.csv'), 'number'))->toBe(['INV-A'])
        ->and(array_column(($this->zipRows)('payments.csv'), 'reference'))->not->toContain('globex secret');
});

it('writes the headers with the timezone of the organization', function () {
    $this->org->update(['timezone' => 'Europe/Paris']);

    ($this->run)([$this->a->id]);

    expect(array_keys(($this->zipRows)('invoices.csv')[0]))->toContain('created at (Europe/Paris)', 'updated at (Europe/Paris)')
        ->and(array_keys(($this->zipRows)('payments.csv')[0]))->toContain('created at (Europe/Paris)');
});

it('reads the home amount at the home currency of the organization', function () {
    $this->org->forceFill(['currency' => 'TND'])->saveQuietly();
    $eur = Invoice::factory()->inCurrency(Currency::Eur, '3.40000000')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-EUR',
        'status' => InvoiceStatus::Sent,
        'amount' => '1000.00',
    ]);
    $tnd = ($this->invoice)('INV-TND', ['currency' => Currency::Tnd]);
    $draft = Invoice::factory()->inCurrency(Currency::Eur, '3.10000000')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-DRAFT',
        'status' => InvoiceStatus::Draft,
        'amount' => '100.00',
    ]);
    $ids = [$eur->id, $tnd->id, $draft->id];

    ($this->run)($ids);
    $csv = ($this->zipRows)('invoices.csv');

    ($this->run)($ids, 'xlsx');
    $xlsx = ($this->sheetRows)('Invoices');

    expect(array_keys($csv[0]))->toContain('home amount (TND)')
        ->and(array_column($csv, 'home amount (TND)'))->toBe(['3400.00', '100.00', ''])
        ->and(array_column($xlsx, 'home amount (TND)'))->toEqual([3400, 100, '']);
});

it('names the home currency of each organization in the header of the home amount', function () {
    ($this->run)([$this->a->id]);

    $rows = ($this->zipRows)('invoices.csv');

    expect(array_keys($rows[0]))->toContain('home amount (USD)')
        ->and($rows[0]['home amount (USD)'])->toBe('100.00');
});

it('falls back to UTC when the timezone of the organization is not valid', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();

    ($this->run)([$this->a->id]);

    expect(array_keys(($this->zipRows)('invoices.csv')[0]))->toContain('created at (UTC)');
});

it('neutralises a formula in the notes of an invoice in the ZIP', function () {
    $evil = ($this->invoice)('INV-EVIL', ['notes' => '=HYPERLINK("http://evil.test","x")']);

    ($this->run)([$evil->id]);

    expect(($this->zipRows)('invoices.csv')[0]['notes'])->toBe('\'=HYPERLINK("http://evil.test","x")');
});

it('keeps the contact of a soft-deleted contact', function () {
    $this->contact->delete();

    ($this->run)([$this->a->id]);

    expect(($this->zipRows)('invoices.csv')[0]['contact'])->toBe($this->contact->name);
});

it('leaves no temporary file behind, in CSV and in XLSX', function (string $format) {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->run)([$this->a->id], $format);

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
})->with(['csv', 'xlsx']);

it('leaves no temporary file and no stored file when writing fails, and sends no "Export ready"', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);
    Invoice::retrieved(function (): never {
        throw new RuntimeException('boom');
    });

    try {
        expect(fn () => ($this->run)([$this->a->id]))->toThrow(RuntimeException::class, 'boom');
    } finally {
        Invoice::flushEventListeners();
        Invoice::clearBootedModels();
    }

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([])
        ->and(Storage::disk('local')->files('exports'))->toBe([])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->get()->map(fn (DatabaseNotification $notification): string => $notification->data['title'])->all())->toBe(['Export failed']);
});

it('tells the user when the export fails', function () {
    (new ExportInvoicesJob([1], $this->org->id, $this->user->id, 'csv', 'invoices.zip'))->failed(new RuntimeException('boom'));

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['title'])->toBe('Export failed');
});

it('runs on a queue a Horizon supervisor listens to, inside the tenant context of its organization', function () {
    $job = new ExportInvoicesJob([1], $this->org->id, $this->user->id, 'csv', 'invoices.zip');
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($job->queue)->toBe('exports')
        ->and($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and($job->middleware()[0])->toBeInstanceOf(ReleasesImportExportSlot::class)
        ->and($job->middleware()[1])->toBeInstanceOf(WithTenantContext::class);
});

it('stores the other formats under their own extension and the CSV as a zip', function () {
    ($this->run)([$this->a->id], 'xlsx');
    $xlsx = basename(($this->storedPath)());
    ($this->run)([$this->a->id], 'csv');
    $zip = basename(($this->storedPath)());

    expect($xlsx)->toEndWith('.xlsx')->and($zip)->toEndWith('.zip')->and($zip)->not->toEndWith('.csv');
});

it('does not run a query per invoice or per payment', function () {
    $queryCount = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->run)($ids);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $one = ($this->invoice)('INV-ONE');
    ($this->payment)($one, '2026-11-01');
    ($this->payment)($one, '2026-11-02');
    $first = $queryCount([$one->id]);

    $three = collect(['INV-T1', 'INV-T2', 'INV-T3'])->map(function (string $number): int {
        $invoice = ($this->invoice)($number, ['deal_id' => null]);
        ($this->payment)($invoice, '2026-11-01');
        ($this->payment)($invoice, '2026-11-02');

        return $invoice->id;
    })->all();

    expect($queryCount($three))->toBe($first);
});
