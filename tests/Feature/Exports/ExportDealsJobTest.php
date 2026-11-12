<?php

use App\Enums\DealStatus;
use App\Jobs\ExportDealsJob;
use App\Jobs\Middleware\ReleasesImportExportSlot;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LostReason;
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

    $this->deal = fn (string $title, array $attributes = []): Deal => Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'company_id' => Company::factory()->create(['organization_id' => $this->org->id, 'custom_field_values' => []])->id,
        'owner_id' => $this->user->id,
        ...$attributes,
    ]);

    $this->exportRows = function (array $ids, string $format = 'xlsx', ?int $organizationId = null): array {
        DatabaseNotification::query()->delete();
        ExportDealsJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "deals-2026-11-10.{$format}");

        $file = Storage::disk('local')->files('exports')[0];

        return SimpleExcelReader::create(Storage::disk('local')->path($file), $format)->getRows()->all();
    };
});

it('tells the user the export is ready with a signed download link', function () {
    $ids = [($this->deal)('Alpha')->id, ($this->deal)('Bravo')->id, ($this->deal)('Charlie')->id];

    ($this->exportRows)($ids);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];
    $file = basename(Storage::disk('local')->files('exports')[0]);

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('3 deals')
        ->and($notification->data['actions'][0]['label'])->toBe('Download')
        ->and($file)->toMatch('/^deals-[A-Za-z0-9]{40}\.xlsx$/')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('name=deals-2026-11-10.xlsx');

    $this->actingAs($this->user)->get($url)->assertOk()->assertDownload('deals-2026-11-10.xlsx');
});

it('says "1 deal" for a single deal', function () {
    ($this->exportRows)([($this->deal)('Alpha')->id], 'csv');

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 deal');
});

it('writes the deals in the order of the given ids and skips a missing id', function () {
    $alpha = ($this->deal)('Alpha');
    $bravo = ($this->deal)('Bravo');
    $charlie = ($this->deal)('Charlie');
    $gone = ($this->deal)('Gone');
    $goneId = $gone->id;
    $gone->delete();

    $rows = ($this->exportRows)([$charlie->id, $goneId, $alpha->id]);

    expect(array_column($rows, 'title'))->toBe(['Charlie', 'Alpha'])
        ->and(array_map('intval', array_column($rows, 'id')))->toBe([$charlie->id, $alpha->id])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('2 deals')
        ->and($bravo->exists)->toBeTrue();
});

it('reads the deals in chunks of 500 and keeps the order across chunks', function () {
    $stage = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id])->pipeline_stage_id;
    $ids = [];

    foreach (range(1, 503) as $number) {
        $ids[] = Deal::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->user->id,
            'pipeline_stage_id' => $stage,
            'title' => sprintf('Deal %03d', $number),
        ])->id;
    }

    $rows = ($this->exportRows)(array_reverse($ids), 'csv');

    expect($rows)->toHaveCount(503)
        ->and($rows[0]['title'])->toBe('Deal 503')
        ->and($rows[502]['title'])->toBe('Deal 001');
});

it('exports only deals of its organization, whatever ids it is given', function () {
    $mine = ($this->deal)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $other->id, 'title' => 'Globex secret']);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'title'))->toBe(['Mine']);
});

it('filters by its own organization even when another tenant is the one in use', function () {
    $mine = ($this->deal)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $other->id, 'title' => 'Globex secret']);
    $this->actingAs($this->user);
    Filament::setTenant($other);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'title'))->toBe(['Mine']);
});

it('writes the headers with the timezone of the organization', function () {
    $this->org->update(['timezone' => 'Europe/Paris']);

    $rows = ($this->exportRows)([($this->deal)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('won at (Europe/Paris)', 'lost at (Europe/Paris)', 'created at (Europe/Paris)', 'updated at (Europe/Paris)');
});

it('falls back to UTC when the timezone of the organization is not valid', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();

    $rows = ($this->exportRows)([($this->deal)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('won at (UTC)', 'created at (UTC)');
});

it('writes the currency of the organization on every row, working only from its tenant context', function () {
    $this->org->forceFill(['currency' => 'EUR'])->saveQuietly();
    $ids = [($this->deal)('Alpha', ['value' => '10.50'])->id, ($this->deal)('Bravo', ['value' => '20.00'])->id];

    $rows = ($this->exportRows)($ids, 'csv');

    expect(Filament::getTenant())->toBeNull()
        ->and(array_column($rows, 'currency'))->toBe(['EUR', 'EUR'])
        ->and(array_column($rows, 'value'))->toBe(['10.50', '20.00']);
});

it('keeps the names of a deleted contact, company and lost reason', function () {
    $ids = [];

    foreach (range(1, 3) as $number) {
        $reason = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => "Reason {$number}"]);
        $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => "Contact {$number}"]);
        $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => "Company {$number}", 'custom_field_values' => []]);
        $deal = ($this->deal)("Deal {$number}", [
            'status' => DealStatus::Lost,
            'lost_at' => '2026-11-01 10:00:00',
            'lost_reason_id' => $reason->id,
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);
        $reason->delete();
        $contact->delete();
        $company->delete();
        $ids[] = $deal->id;
    }

    $rows = ($this->exportRows)($ids, 'csv');

    expect(array_column($rows, 'contact'))->toBe(['Contact 1', 'Contact 2', 'Contact 3'])
        ->and(array_column($rows, 'company'))->toBe(['Company 1', 'Company 2', 'Company 3'])
        ->and(array_column($rows, 'lost reason'))->toBe(['Reason 1', 'Reason 2', 'Reason 3']);
});

it('neutralises a formula in a deal title in the file', function () {
    $rows = ($this->exportRows)([($this->deal)('=HYPERLINK("http://evil.test","x")')->id], 'csv');

    expect($rows[0]['title'])->toBe('\'=HYPERLINK("http://evil.test","x")');
});

it('leaves no temporary file behind', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->exportRows)([($this->deal)('Alpha')->id], 'csv');

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
});

it('tells the user when the export fails', function () {
    (new ExportDealsJob([1], $this->org->id, $this->user->id, 'xlsx', 'deals.xlsx'))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Export failed');
});

it('runs on a queue a Horizon supervisor listens to, inside the tenant context of its organization', function () {
    $job = new ExportDealsJob([1], $this->org->id, $this->user->id, 'xlsx', 'deals.xlsx');
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($job->queue)->toBe('imports')
        ->and($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and($job->middleware())->toHaveCount(2)
        ->and($job->middleware()[0])->toBeInstanceOf(ReleasesImportExportSlot::class)
        ->and($job->middleware()[1])->toBeInstanceOf(WithTenantContext::class);
});

it('exports the relations of the deals without a query per deal', function () {
    $ids = [];

    foreach (range(1, 6) as $number) {
        $ids[] = ($this->deal)("Deal {$number}")->id;
    }

    DB::enableQueryLog();
    ($this->exportRows)($ids, 'csv');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    foreach (['contacts', 'companies', 'pipeline_stages'] as $table) {
        expect($queries->filter(fn (string $query): bool => str_contains($query, "from \"{$table}\""))->count())->toBe(1, $table);
    }
});
