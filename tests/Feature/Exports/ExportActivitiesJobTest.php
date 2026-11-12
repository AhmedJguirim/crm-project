<?php

use App\Enums\ActivityType;
use App\Jobs\ExportActivitiesJob;
use App\Jobs\Middleware\ReleasesImportExportSlot;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
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

    $this->activity = fn (string $subject, array $attributes = []): Activity => Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'deal_id' => Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id])->id,
        'company_id' => Company::factory()->create(['organization_id' => $this->org->id])->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Note,
        'subject' => $subject,
        ...$attributes,
    ]);

    $this->exportRows = function (array $ids, string $format = 'xlsx', ?int $organizationId = null): array {
        DatabaseNotification::query()->delete();
        ExportActivitiesJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "activities-2026-11-10.{$format}");

        $file = Storage::disk('local')->files('exports')[0];

        return SimpleExcelReader::create(Storage::disk('local')->path($file), $format)->getRows()->all();
    };
});

it('tells the user the export is ready with a signed download link', function () {
    $ids = [($this->activity)('Alpha')->id, ($this->activity)('Bravo')->id, ($this->activity)('Charlie')->id];

    ($this->exportRows)($ids);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];
    $file = basename(Storage::disk('local')->files('exports')[0]);

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('3 activities')
        ->and($notification->data['actions'][0]['label'])->toBe('Download')
        ->and($file)->toMatch('/^activities-[A-Za-z0-9]{40}\.xlsx$/')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('name=activities-2026-11-10.xlsx');

    $this->actingAs($this->user)->get($url)->assertOk()->assertDownload('activities-2026-11-10.xlsx');
});

it('says "1 activity" for a single activity', function () {
    ($this->exportRows)([($this->activity)('Alpha')->id], 'csv');

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 activity');
});

it('writes the activities in the order of the given ids and skips missing and deleted activities', function () {
    $alpha = ($this->activity)('Alpha');
    $bravo = ($this->activity)('Bravo');
    $charlie = ($this->activity)('Charlie');
    $deleted = ($this->activity)('Deleted');
    $deleted->delete();
    $missingId = $deleted->id + 1000;

    $rows = ($this->exportRows)([$charlie->id, $deleted->id, $missingId, $alpha->id]);

    expect(array_column($rows, 'subject'))->toBe(['Charlie', 'Alpha'])
        ->and(array_map('intval', array_column($rows, 'id')))->toBe([$charlie->id, $alpha->id])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('2 activities')
        ->and($bravo->exists)->toBeTrue();
});

it('reads the activities in chunks of 500 and keeps the order across chunks', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $ids = [];

    foreach (range(1, 503) as $number) {
        $ids[] = Activity::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'user_id' => $this->user->id,
            'subject' => sprintf('Activity %03d', $number),
        ])->id;
    }

    $rows = ($this->exportRows)(array_reverse($ids), 'csv');

    expect($rows)->toHaveCount(503)
        ->and($rows[0]['subject'])->toBe('Activity 503')
        ->and($rows[502]['subject'])->toBe('Activity 001');
});

it('exports only activities of its organization, whatever ids it is given', function () {
    $mine = ($this->activity)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Activity::factory()->create(['organization_id' => $other->id, 'subject' => 'Globex secret']);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'subject'))->toBe(['Mine']);
});

it('filters by its own organization even when another tenant is the one in use', function () {
    $mine = ($this->activity)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Activity::factory()->create(['organization_id' => $other->id, 'subject' => 'Globex secret']);
    $this->actingAs($this->user);
    Filament::setTenant($other);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'subject'))->toBe(['Mine']);
});

it('writes the headers with the timezone of the organization', function () {
    $this->org->update(['timezone' => 'Europe/Paris']);

    $rows = ($this->exportRows)([($this->activity)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('occurred at (Europe/Paris)', 'created at (Europe/Paris)', 'updated at (Europe/Paris)');
});

it('falls back to UTC when the timezone of the organization is not valid', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();

    $rows = ($this->exportRows)([($this->activity)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('occurred at (UTC)', 'created at (UTC)');
});

it('writes the dates in the timezone of the organization, working only from its tenant context', function () {
    $this->org->update(['timezone' => 'Pacific/Auckland']);
    $ids = [
        ($this->activity)('Alpha', ['occurred_at' => '2026-07-01 22:30:00'])->id,
        ($this->activity)('Bravo', ['occurred_at' => '2026-07-02 22:30:00'])->id,
    ];

    $rows = ($this->exportRows)($ids, 'csv');

    expect(Filament::getTenant())->toBeNull()
        ->and(array_column($rows, 'occurred at (Pacific/Auckland)'))->toBe(['02-07-2026 10:30', '03-07-2026 10:30']);
});

it('keeps the names of a deleted contact and a deleted company', function () {
    $ids = [];

    foreach (range(1, 3) as $number) {
        $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => "Contact {$number}"]);
        $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => "Company {$number}"]);
        $activity = ($this->activity)("Activity {$number}", ['contact_id' => $contact->id, 'company_id' => $company->id]);
        $contact->delete();
        $company->delete();
        $ids[] = $activity->id;
    }

    $rows = ($this->exportRows)($ids, 'csv');

    expect(array_column($rows, 'contact'))->toBe(['Contact 1', 'Contact 2', 'Contact 3'])
        ->and(array_column($rows, 'company'))->toBe(['Company 1', 'Company 2', 'Company 3']);
});

it('neutralises a formula in an activity subject in the file', function () {
    $rows = ($this->exportRows)([($this->activity)('=HYPERLINK("http://evil.test","x")')->id], 'csv');

    expect($rows[0]['subject'])->toBe('\'=HYPERLINK("http://evil.test","x")');
});

it('leaves no temporary file behind', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->exportRows)([($this->activity)('Alpha')->id], 'csv');

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
});

it('tells the user when the export fails', function () {
    (new ExportActivitiesJob([1], $this->org->id, $this->user->id, 'xlsx', 'activities.xlsx'))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Export failed');
});

it('runs on a queue a Horizon supervisor listens to, inside the tenant context of its organization', function () {
    $job = new ExportActivitiesJob([1], $this->org->id, $this->user->id, 'xlsx', 'activities.xlsx');
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

it('exports the relations of the activities without a query per activity', function () {
    $ids = [];

    foreach (range(1, 6) as $number) {
        $ids[] = ($this->activity)("Activity {$number}")->id;
    }

    DB::enableQueryLog();
    ($this->exportRows)($ids, 'csv');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    foreach (['contacts', 'deals', 'companies'] as $table) {
        expect($queries->filter(fn (string $query): bool => str_contains($query, "from \"{$table}\""))->count())->toBe(1, $table);
    }
});
