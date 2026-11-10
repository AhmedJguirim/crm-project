<?php

use App\Enums\ExportFormat;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Imports\TextPreservingExcelReader;
use App\Services\TaskExportWriter;
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

    $this->writer = fn (string $timezone = 'Pacific/Auckland'): TaskExportWriter => new TaskExportWriter($timezone);

    $this->task = fn (array $attributes = []): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Plain task',
        'type' => TaskType::Other,
        'priority' => TaskPriority::Medium,
        'due_at' => null,
        'notes' => null,
        ...$attributes,
    ]);

    $this->load = fn (Task $task): Task => Task::query()
        ->with(['owner', 'contact' => fn (BelongsTo $query) => $query->withTrashed(), 'deal'])
        ->findOrFail($task->id);
});

it('names the timezone on the four timestamps only', function () {
    expect(($this->writer)()->headers())->toBe([
        'id', 'title', 'type', 'priority', 'status', 'due at (Pacific/Auckland)', 'completed at (Pacific/Auckland)', 'owner',
        'contact id', 'contact', 'contact email', 'deal id', 'deal', 'notes',
        'created at (Pacific/Auckland)', 'updated at (Pacific/Auckland)',
    ])->and(($this->writer)()->headers())->toHaveCount(16);
});

it('writes a done task with every link, in the organization timezone', function () {
    $ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'title' => 'Website redesign']);
    $olga = User::factory()->create(['email' => 'olga@example.com']);
    $task = ($this->task)([
        'title' => 'Send the proposal',
        'type' => TaskType::Email,
        'priority' => TaskPriority::High,
        'status' => TaskStatus::Done,
        'due_at' => '2026-07-01 22:30:00',
        'completed_at' => '2026-03-29 00:30:00',
        'created_at' => '2026-03-28 23:30:00',
        'updated_at' => '2026-07-01 22:30:00',
        'notes' => 'Attach the PDF',
        'contact_id' => $ann->id,
        'deal_id' => $deal->id,
        'owner_id' => $olga->id,
    ]);

    expect(($this->writer)()->row(($this->load)($task), ExportFormat::Csv))->toBe([
        $task->id, 'Send the proposal', 'Email', 'High', 'Done', '02-07-2026 10:30', '29-03-2026 13:30', 'olga@example.com',
        $ann->id, 'Ann Lee', 'ann@example.com', $deal->id, 'Website redesign', 'Attach the PDF', '29-03-2026 12:30', '02-07-2026 10:30',
    ]);
});

it('labels the follow-up type', function () {
    $task = ($this->task)(['type' => TaskType::FollowUp, 'priority' => TaskPriority::Low, 'status' => TaskStatus::Pending]);

    $cells = array_combine(TaskExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($task), ExportFormat::Csv));

    expect($cells['type'])->toBe('Follow-up')
        ->and($cells['priority'])->toBe('Low')
        ->and($cells['status'])->toBe('Pending');
});

it('writes empty cells for what a task does not have', function () {
    $task = ($this->task)();

    $cells = array_combine(TaskExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($task), ExportFormat::Csv));

    expect(array_intersect_key($cells, array_flip(['due at', 'completed at', 'owner', 'contact id', 'contact', 'contact email', 'deal id', 'deal', 'notes'])))
        ->each->toBe('');
});

it('keeps the name and email of a soft-deleted contact', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $task = ($this->task)(['contact_id' => $contact->id]);
    $contact->delete();

    $cells = array_combine(TaskExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($task), ExportFormat::Csv));

    expect($cells['contact id'])->toBe($contact->id)
        ->and($cells['contact'])->toBe('Ann Lee')
        ->and($cells['contact email'])->toBe('ann@example.com');
});

it('writes empty deal cells when the deal no longer exists', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id]);
    $task = ($this->task)(['deal_id' => $deal->id]);
    $deal->delete();

    $cells = array_combine(TaskExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($task), ExportFormat::Csv));

    expect($task->fresh()->deal_id)->toBe($deal->id)
        ->and($cells['deal id'])->toBe('')
        ->and($cells['deal'])->toBe('');
});

it('neutralises a formula in a CSV and keeps it as text in an xlsx', function () {
    $task = ($this->task)(['title' => '=HYPERLINK("http://evil.test","x")', 'notes' => '@SUM(1)']);
    $csvPath = tempnam(sys_get_temp_dir(), 'task-export').'.csv';
    $xlsxPath = tempnam(sys_get_temp_dir(), 'task-export').'.xlsx';

    ($this->writer)()->write($csvPath, ExportFormat::Csv, [[($this->load)($task)]]);
    $count = ($this->writer)()->write($xlsxPath, ExportFormat::Xlsx, [[($this->load)($task)]]);

    $csv = SimpleExcelReader::create($csvPath, 'csv')->getRows()->first();
    $xlsx = TextPreservingExcelReader::create($xlsxPath, 'xlsx')->getRows()->first();
    unlink($csvPath);
    unlink($xlsxPath);

    expect($count)->toBe(1)
        ->and($csv['title'])->toBe('\'=HYPERLINK("http://evil.test","x")')
        ->and($csv['notes'])->toBe("'@SUM(1)")
        ->and($xlsx['title'])->toBe('=HYPERLINK("http://evil.test","x")');
});

it('neutralises a formula in the owner and contact cells of a CSV', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => '+cmd|calc', 'email' => 'ann@example.com']);
    $task = ($this->task)(['contact_id' => $contact->id]);

    $cells = array_combine(TaskExportWriter::COLUMNS, ($this->writer)()->row(($this->load)($task), ExportFormat::Csv));

    expect($cells['contact'])->toBe("'+cmd|calc");
});
