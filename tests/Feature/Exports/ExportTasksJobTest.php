<?php

use App\Jobs\ExportTasksJob;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
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

    $this->task = fn (string $title, array $attributes = []): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'deal_id' => Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id])->id,
        'owner_id' => $this->user->id,
        ...$attributes,
    ]);

    $this->exportRows = function (array $ids, string $format = 'xlsx', ?int $organizationId = null): array {
        DatabaseNotification::query()->delete();
        ExportTasksJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "tasks-2026-11-10.{$format}");

        $file = Storage::disk('local')->files('exports')[0];

        return SimpleExcelReader::create(Storage::disk('local')->path($file), $format)->getRows()->all();
    };
});

it('tells the user the export is ready with a signed download link', function () {
    $ids = [($this->task)('Alpha')->id, ($this->task)('Bravo')->id, ($this->task)('Charlie')->id];

    ($this->exportRows)($ids);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];
    $file = basename(Storage::disk('local')->files('exports')[0]);

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('3 tasks')
        ->and($notification->data['actions'][0]['label'])->toBe('Download')
        ->and($file)->toMatch('/^tasks-[A-Za-z0-9]{40}\.xlsx$/')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('name=tasks-2026-11-10.xlsx');

    $this->actingAs($this->user)->get($url)->assertOk()->assertDownload('tasks-2026-11-10.xlsx');
});

it('says "1 task" for a single task', function () {
    ($this->exportRows)([($this->task)('Alpha')->id], 'csv');

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 task');
});

it('writes the tasks in the order of the given ids and skips missing and soft-deleted tasks', function () {
    $alpha = ($this->task)('Alpha');
    $bravo = ($this->task)('Bravo');
    $charlie = ($this->task)('Charlie');
    $deleted = ($this->task)('Deleted');
    $deleted->delete();
    $missingId = $deleted->id + 1000;

    $rows = ($this->exportRows)([$charlie->id, $deleted->id, $missingId, $alpha->id]);

    expect(array_column($rows, 'title'))->toBe(['Charlie', 'Alpha'])
        ->and(array_map('intval', array_column($rows, 'id')))->toBe([$charlie->id, $alpha->id])
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('2 tasks')
        ->and($bravo->exists)->toBeTrue();
});

it('reads the tasks in chunks of 500 and keeps the order across chunks', function () {
    $ids = [];

    foreach (range(1, 503) as $number) {
        $ids[] = Task::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->user->id,
            'title' => sprintf('Task %03d', $number),
        ])->id;
    }

    $rows = ($this->exportRows)(array_reverse($ids), 'csv');

    expect($rows)->toHaveCount(503)
        ->and($rows[0]['title'])->toBe('Task 503')
        ->and($rows[502]['title'])->toBe('Task 001');
});

it('exports only tasks of its organization, whatever ids it is given', function () {
    $mine = ($this->task)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Task::factory()->create(['organization_id' => $other->id, 'title' => 'Globex secret']);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'title'))->toBe(['Mine']);
});

it('filters by its own organization even when another tenant is the one in use', function () {
    $mine = ($this->task)('Mine');
    $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
    $theirs = Task::factory()->create(['organization_id' => $other->id, 'title' => 'Globex secret']);
    $this->actingAs($this->user);
    Filament::setTenant($other);

    $rows = ($this->exportRows)([$mine->id, $theirs->id]);

    expect(array_column($rows, 'title'))->toBe(['Mine']);
});

it('writes the headers with the timezone of the organization', function () {
    $this->org->update(['timezone' => 'Europe/Paris']);

    $rows = ($this->exportRows)([($this->task)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('due at (Europe/Paris)', 'completed at (Europe/Paris)', 'created at (Europe/Paris)', 'updated at (Europe/Paris)');
});

it('falls back to UTC when the timezone of the organization is not valid', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();

    $rows = ($this->exportRows)([($this->task)('Alpha')->id]);

    expect(array_keys($rows[0]))->toContain('due at (UTC)', 'created at (UTC)');
});

it('writes the dates in the timezone of the organization, working only from its tenant context', function () {
    $this->org->update(['timezone' => 'Pacific/Auckland']);
    $ids = [
        ($this->task)('Alpha', ['due_at' => '2026-07-01 22:30:00'])->id,
        ($this->task)('Bravo', ['due_at' => '2026-07-02 22:30:00'])->id,
    ];

    $rows = ($this->exportRows)($ids, 'csv');

    expect(Filament::getTenant())->toBeNull()
        ->and(array_column($rows, 'due at (Pacific/Auckland)'))->toBe(['02-07-2026 10:30', '03-07-2026 10:30']);
});

it('keeps the name of a deleted contact and leaves the deal empty when it no longer exists', function () {
    $ids = [];
    $dealIds = [];

    foreach (range(1, 3) as $number) {
        $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => "Contact {$number}"]);
        $task = ($this->task)("Task {$number}", ['contact_id' => $contact->id]);
        $contact->delete();
        $ids[] = $task->id;
        $dealIds[] = $task->deal_id;
    }

    Deal::query()->withoutGlobalScope('organization')->findOrFail($dealIds[0])->delete();

    $rows = ($this->exportRows)($ids, 'csv');

    expect(array_column($rows, 'contact'))->toBe(['Contact 1', 'Contact 2', 'Contact 3'])
        ->and($rows[0]['deal'])->toBe('')
        ->and($rows[0]['deal id'])->toBe('')
        ->and($rows[1]['deal id'])->toBe((string) $dealIds[1]);
});

it('neutralises a formula in a task title in the file', function () {
    $rows = ($this->exportRows)([($this->task)('=HYPERLINK("http://evil.test","x")')->id], 'csv');

    expect($rows[0]['title'])->toBe('\'=HYPERLINK("http://evil.test","x")');
});

it('leaves no temporary file behind', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->exportRows)([($this->task)('Alpha')->id], 'csv');

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
});

it('tells the user when the export fails', function () {
    (new ExportTasksJob([1], $this->org->id, $this->user->id, 'xlsx', 'tasks.xlsx'))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Export failed');
});

it('runs on a queue a Horizon supervisor listens to, inside the tenant context of its organization', function () {
    $job = new ExportTasksJob([1], $this->org->id, $this->user->id, 'xlsx', 'tasks.xlsx');
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($job->queue)->toBe('imports')
        ->and($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithTenantContext::class);
});

it('exports the relations of the tasks without a query per task', function () {
    $ids = [];

    foreach (range(1, 6) as $number) {
        $ids[] = ($this->task)("Task {$number}")->id;
    }

    DB::enableQueryLog();
    ($this->exportRows)($ids, 'csv');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    foreach (['contacts', 'deals'] as $table) {
        expect($queries->filter(fn (string $query): bool => str_contains($query, "from \"{$table}\""))->count())->toBe(1, $table);
    }
});
