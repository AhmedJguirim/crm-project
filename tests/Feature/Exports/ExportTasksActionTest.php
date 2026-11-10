<?php

use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Jobs\ExportTasksJob;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->org->update(['timezone' => 'Pacific/Auckland']);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->task = fn (string $title, ?string $dueAt, array $attributes = []): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->owner->id,
        'owner_id' => $this->owner->id,
        'title' => $title,
        'due_at' => $dueAt,
        'status' => TaskStatus::Pending,
        'notes' => null,
        ...$attributes,
    ]);

    $this->alpha = ($this->task)('Alpha', '2026-11-01 10:00:00');
    $this->bravo = ($this->task)('Bravo', '2026-11-02 10:00:00');
    $this->charlie = ($this->task)('Charlie', '2026-11-03 10:00:00');
    $this->delta = ($this->task)('Delta', '2026-11-04 10:00:00', ['status' => TaskStatus::Done, 'completed_at' => '2026-11-04 11:00:00']);

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportTasksJob::class)
            ->map(fn (ExportTasksJob $job): array => (fn (): array => [
                'ids' => $this->taskIds,
                'organization' => $this->organizationId,
                'user' => $this->userId,
                'format' => $this->format,
                'name' => $this->downloadName,
            ])->call($job))
            ->values()
            ->all();
    };
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports what the list shows by default: the pending tasks in due-date order', function () {
        Livewire::test(ListTasks::class)
            ->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->alpha->id, $this->bravo->id, $this->charlie->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('puts a pending task without a due date last', function () {
        $echo = ($this->task)('Echo', null);

        Livewire::test(ListTasks::class)->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id, $this->bravo->id, $this->charlie->id, $echo->id]);
    });

    it('follows the active tab and the filters', function () {
        $this->alpha->forceFill(['owner_id' => $this->owner->id])->saveQuietly();
        $this->bravo->forceFill(['owner_id' => $this->admin->id])->saveQuietly();
        $this->charlie->forceFill(['owner_id' => $this->owner->id])->saveQuietly();

        Livewire::test(ListTasks::class)
            ->set('activeTab', 'mine')
            ->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bravo->id]);

        Queue::fake();

        Livewire::test(ListTasks::class)
            ->set('activeTab', 'all')
            ->filterTable('status', TaskStatus::Done->value)
            ->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->delta->id]);
    });

    it('exports only the tasks of the organization in use, and not the deleted ones', function () {
        Task::factory()->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id]);
        $this->charlie->delete();

        Livewire::test(ListTasks::class)->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id, $this->bravo->id]);
    });

    it('names the file with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListTasks::class)->callAction('exportTasks', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe('tasks-2026-10-07.csv');
    });

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListTasks::class)
            ->mountAction('exportTasks')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportTasksJob::class);
    });

    it('warns when there is nothing to export', function () {
        Livewire::test(ListTasks::class)
            ->searchTable('nobody matches this')
            ->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportTasksJob::class);
    });

    it('exports the selected tasks as a csv', function () {
        Livewire::test(ListTasks::class)
            ->callTableBulkAction('exportSelected', [$this->alpha, $this->charlie], ['format' => ExportFormat::Csv->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toEqualCanonicalizing([$this->alpha->id, $this->charlie->id])
            ->and($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['user'])->toBe($this->admin->id);
    });

    it('exports nothing of another organization when the selection is forged', function () {
        $foreign = Task::factory()->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id, 'title' => 'Globex secret']);

        Livewire::test(ListTasks::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $foreign->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Csv->value])
            ->callMountedAction();

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id]);
    });

    it('writes a file with only the selected tasks', function () {
        Storage::fake('local');

        Livewire::test(ListTasks::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $this->charlie->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Csv->value])
            ->callMountedAction();

        Queue::pushed(ExportTasksJob::class)->sole()->handle();

        $file = Storage::disk('local')->files('exports')[0];
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file), 'csv')->getRows()->all();

        expect(array_column($rows, 'title'))->toEqualCanonicalizing(['Alpha', 'Charlie']);
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListTasks::class)
            ->assertActionVisible('exportTasks')
            ->assertTableBulkActionVisible('exportSelected')
            ->callAction('exportTasks', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportTasksJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('sees neither export action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListTasks::class)
            ->assertActionHidden('exportTasks')
            ->assertTableBulkActionHidden('exportSelected');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('is refused when calling the actions anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListTasks::class)
            ->call('mountAction', 'exportTasks', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ListTasks::class)
            ->set('selectedTableRecords', [$this->alpha->getKey()])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportTasksJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
