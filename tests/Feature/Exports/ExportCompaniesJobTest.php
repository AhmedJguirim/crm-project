<?php

use App\Jobs\ExportCompaniesJob;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\User;
use App\Support\TemporaryFile;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->company = fn (string $name, array $attributes = []): Company => Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'custom_field_values' => [],
        ...$attributes,
    ]);

    $this->exportRows = function (array $ids, string $format = 'xlsx', ?int $organizationId = null): array {
        DatabaseNotification::query()->delete();
        ExportCompaniesJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "companies-2026-10-07.{$format}");

        $file = Storage::disk('local')->files('exports')[0];

        return SimpleExcelReader::create(Storage::disk('local')->path($file), $format)->getRows()->all();
    };
});

it('tells the user the export is ready with a signed download link', function () {
    $ids = [($this->company)('Ann')->id, ($this->company)('Bob')->id, ($this->company)('Cid')->id];

    ($this->exportRows)($ids);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];
    $file = basename(Storage::disk('local')->files('exports')[0]);

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('3 companies')
        ->and($notification->data['actions'][0]['label'])->toBe('Download')
        ->and($file)->toMatch('/^companies-[A-Za-z0-9]{40}\.xlsx$/')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain("file={$file}")
        ->and($url)->toContain('name=companies-2026-10-07.xlsx')
        ->and($url)->toContain("user={$this->user->id}");

    $this->actingAs($this->user)->get($url)->assertOk()->assertDownload('companies-2026-10-07.xlsx');
});

it('says "1 company" for a single company', function () {
    ($this->exportRows)([($this->company)('Ann')->id], 'csv');

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 company');
});

it('writes the companies in the order of the given ids, including trashed ones, and skips a missing id', function () {
    $ann = ($this->company)('Ann');
    $bob = ($this->company)('Bob');
    $cid = ($this->company)('Cid');
    $bob->delete();

    $rows = ($this->exportRows)([$cid->id, 999999, $bob->id, $ann->id]);

    expect(array_column($rows, 'name'))->toBe(['Cid', 'Bob', 'Ann'])
        ->and(array_slice(array_keys($rows[0]), 0, 2))->toBe(['id', 'name'])
        ->and(array_map('intval', array_column($rows, 'id')))->toBe([$cid->id, $bob->id, $ann->id]);
});

it('reads the companies in chunks of 500 and keeps the order across chunks', function () {
    $ids = [];

    foreach (range(1, 503) as $number) {
        $ids[] = Company::factory()->create(['organization_id' => $this->org->id, 'name' => sprintf('Company %03d', $number), 'custom_field_values' => []])->id;
    }

    $rows = ($this->exportRows)(array_reverse($ids), 'csv');

    expect($rows)->toHaveCount(503)
        ->and($rows[0]['name'])->toBe('Company 503')
        ->and($rows[502]['name'])->toBe('Company 001');
});

it('exports only companies of its organization, whatever ids it is given', function () {
    $mine = ($this->company)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Company::factory()->create(['organization_id' => $other->id, 'name' => 'Theirs', 'custom_field_values' => []]);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'name'))->toBe(['Mine']);
});

it('filters by its own organization even when another tenant is the one in use', function () {
    $mine = ($this->company)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Company::factory()->create(['organization_id' => $other->id, 'name' => 'Theirs', 'custom_field_values' => []]);
    $this->actingAs($this->user);
    Filament::setTenant($other);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'name'))->toBe(['Mine']);
});

it('writes the contacts count, the type and the address, working only from its tenant context', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Client']);
    $acme = ($this->company)('Acme', [
        'company_type_id' => $type->id,
        'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'city' => 'Lyon'])->id,
    ]);
    $acme->contacts()->attach([
        Contact::factory()->create(['organization_id' => $this->org->id])->id,
        Contact::factory()->create(['organization_id' => $this->org->id])->id,
    ]);

    $row = ($this->exportRows)([$acme->id], 'csv')[0];

    expect(Filament::getTenant())->toBeNull()
        ->and($row['contacts'])->toBe('2')
        ->and($row['type'])->toBe('Client')
        ->and($row['city'])->toBe('Lyon');
});

it('leaves no temporary file behind', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->exportRows)([($this->company)('Ann')->id], 'csv');

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
});

it('tells the user when the export fails', function () {
    (new ExportCompaniesJob([1], $this->org->id, $this->user->id, 'xlsx', 'companies.xlsx'))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Export failed')
        ->and($notification->data['body'])->toBe('Something went wrong while building the file. Try again, or contact support if it keeps failing.');
});

it('runs on a queue a Horizon supervisor listens to, with a timeout the supervisor outlives', function () {
    $job = new ExportCompaniesJob([1], $this->org->id, $this->user->id, 'xlsx', 'companies.xlsx');
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($job->queue)->toBe('imports')
        ->and($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBe(1740)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(1);
});

it('runs inside the tenant context of its organization', function () {
    $middleware = (new ExportCompaniesJob([1], $this->org->id, $this->user->id, 'xlsx', 'companies.xlsx'))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithTenantContext::class);
});

it('exports the addresses and types of the companies without a query per company', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Client']);
    $ids = [];

    foreach (range(1, 6) as $number) {
        $ids[] = ($this->company)("Company {$number}", [
            'company_type_id' => $type->id,
            'address_id' => Address::factory()->create(['organization_id' => $this->org->id])->id,
        ])->id;
    }

    DB::enableQueryLog();
    ($this->exportRows)($ids, 'csv');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $query): bool => str_contains($query, 'from "addresses"'))->count())->toBe(1)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from "company_types"'))->count())->toBe(1);
});
