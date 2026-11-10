<?php

use App\Enums\DealStatus;
use App\Enums\ExportFormat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LostReason;
use App\Models\User;
use App\Services\DealExportWriter;
use App\Services\Imports\TextPreservingExcelReader;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->writer = fn (string $timezone = 'Pacific/Auckland'): DealExportWriter => new DealExportWriter($timezone, 'EUR');

    $this->deal = fn (array $attributes = [], string $stage = 'Negotiating'): Deal => Deal::factory()->inStage($stage)->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Plain deal',
        'value' => null,
        'expected_close_date' => null,
        'notes' => null,
        ...$attributes,
    ]);

    $this->load = fn (Deal $deal): Deal => Deal::query()
        ->with(['pipelineStage', 'lostReason', 'owner', 'contact' => fn (BelongsTo $query) => $query->withTrashed(), 'companyWithTrashed'])
        ->findOrFail($deal->id);
});

it('names the timezone on the four timestamps only', function () {
    expect(($this->writer)()->headers())->toBe([
        'id', 'title', 'value', 'currency', 'stage', 'status', 'expected close date', 'won at (Pacific/Auckland)', 'lost at (Pacific/Auckland)',
        'lost reason', 'lost reason note', 'owner', 'contact id', 'contact', 'contact email', 'company id', 'company', 'notes',
        'created at (Pacific/Auckland)', 'updated at (Pacific/Auckland)',
    ])->and(($this->writer)()->headers())->toHaveCount(20);
});

it('writes a won deal with every link, in the organization timezone', function () {
    $ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'custom_field_values' => []]);
    $olga = User::factory()->create(['email' => 'olga@example.com']);
    $deal = ($this->deal)([
        'title' => 'Website redesign',
        'value' => '1234.50',
        'status' => DealStatus::Won,
        'expected_close_date' => '2026-07-01',
        'won_at' => '2026-07-01 22:30:00',
        'created_at' => '2026-03-28 23:30:00',
        'updated_at' => '2026-07-01 22:30:00',
        'notes' => 'Paid upfront',
        'contact_id' => $ann->id,
        'company_id' => $acme->id,
        'owner_id' => $olga->id,
    ], 'Won');

    expect(($this->writer)()->row(($this->load)($deal), ExportFormat::Csv))->toBe([
        $deal->id, 'Website redesign', '1234.50', 'EUR', 'Won', 'Won', '01-07-2026', '02-07-2026 10:30', '', '', '', 'olga@example.com',
        $ann->id, 'Ann Lee', 'ann@example.com', $acme->id, 'Acme Ltd', 'Paid upfront', '29-03-2026 12:30', '02-07-2026 10:30',
    ]);
});

it('never shifts the expected close date with the timezone', function (string $timezone) {
    $deal = ($this->deal)(['expected_close_date' => '2026-07-01']);

    $cells = array_combine(DealExportWriter::COLUMNS, ($this->writer)($timezone)->row(($this->load)($deal), ExportFormat::Csv));

    expect($cells['expected close date'])->toBe('01-07-2026');
})->with(['America/New_York', 'Pacific/Auckland', 'UTC']);

it('writes a lost deal with a deleted lost reason, contact and company', function () {
    $reason = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Too expensive']);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'custom_field_values' => []]);
    $deal = ($this->deal)([
        'status' => DealStatus::Lost,
        'lost_at' => '2026-03-29 00:30:00',
        'lost_reason_id' => $reason->id,
        'lost_reason_note' => 'Chose a cheaper agency',
        'contact_id' => $contact->id,
        'company_id' => $company->id,
    ], 'Lost');
    $reason->delete();
    $contact->delete();
    $company->delete();

    $cells = array_combine(DealExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($deal), ExportFormat::Csv));

    expect($cells['lost at'])->toBe('29-03-2026 13:30')
        ->and($cells['status'])->toBe('Lost')
        ->and($cells['lost reason'])->toBe('Too expensive')
        ->and($cells['lost reason note'])->toBe('Chose a cheaper agency')
        ->and($cells['won at'])->toBe('')
        ->and($cells['contact id'])->toBe($contact->id)
        ->and($cells['contact'])->toBe('Ann Lee')
        ->and($cells['contact email'])->toBe('ann@example.com')
        ->and($cells['company id'])->toBe($company->id)
        ->and($cells['company'])->toBe('Acme Ltd');
});

it('writes empty cells for what a deal does not have', function () {
    $deal = ($this->deal)();

    $cells = array_combine(DealExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($deal), ExportFormat::Csv));

    expect($cells['value'])->toBe('')
        ->and($cells['stage'])->toBe('Negotiating')
        ->and($cells['status'])->toBe('Open')
        ->and(array_intersect_key($cells, array_flip(['expected close date', 'won at', 'lost at', 'lost reason', 'lost reason note', 'owner', 'contact id', 'contact', 'contact email', 'company id', 'company', 'notes'])))
        ->each->toBe('');
});

it('neutralises a formula in a CSV and keeps it as text in an xlsx', function () {
    $deal = ($this->deal)(['title' => '=HYPERLINK("http://evil.test","x")', 'notes' => '@SUM(1)']);
    $csvPath = tempnam(sys_get_temp_dir(), 'deal-export').'.csv';
    $xlsxPath = tempnam(sys_get_temp_dir(), 'deal-export').'.xlsx';

    ($this->writer)()->write($csvPath, ExportFormat::Csv, [[($this->load)($deal)]]);
    ($this->writer)()->write($xlsxPath, ExportFormat::Xlsx, [[($this->load)($deal)]]);

    $csv = SimpleExcelReader::create($csvPath, 'csv')->getRows()->first();
    $xlsx = TextPreservingExcelReader::create($xlsxPath, 'xlsx')->getRows()->first();
    unlink($csvPath);
    unlink($xlsxPath);

    expect($csv['title'])->toBe('\'=HYPERLINK("http://evil.test","x")')
        ->and($csv['notes'])->toBe("'@SUM(1)")
        ->and($xlsx['title'])->toBe('=HYPERLINK("http://evil.test","x")');
});

it('writes the money as a number in an xlsx and as exact text in a csv', function () {
    $deal = ($this->deal)(['value' => '99999999.99']);
    $xlsxPath = tempnam(sys_get_temp_dir(), 'deal-export').'.xlsx';
    $csvPath = tempnam(sys_get_temp_dir(), 'deal-export').'.csv';

    ($this->writer)()->write($xlsxPath, ExportFormat::Xlsx, [[($this->load)($deal)]]);
    $count = ($this->writer)()->write($csvPath, ExportFormat::Csv, [[($this->load)($deal)]]);

    $xlsx = TextPreservingExcelReader::create($xlsxPath, 'xlsx')->getRows()->first();
    $csv = SimpleExcelReader::create($csvPath, 'csv')->getRows()->first();
    unlink($xlsxPath);
    unlink($csvPath);

    expect($count)->toBe(1)
        ->and($xlsx['value'])->toBe(99999999.99)
        ->and($csv['value'])->toBe('99999999.99');
});
