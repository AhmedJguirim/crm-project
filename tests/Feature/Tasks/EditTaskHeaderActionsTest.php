<?php

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 08:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->alice->personalOrganization();
    $this->org->update(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($this->alice);
    Filament::setTenant($this->org->fresh());

    $this->task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'owner_id' => $this->alice->id,
        'title' => 'Call back',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-10-15 00:00:00',
    ]);
});

afterEach(function () {
    Filament::setTenant(null);
    Carbon::setTestNow();
});

test('a quick snooze shows the new due date and survives Save', function (string $action, string $local, string $utc) {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->assertSet('data.due_at', '2026-10-15 09:00:00')
        ->callAction($action)
        ->assertSet('data.due_at', $local)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe($utc);
})->with([
    'one day' => ['snooze1Day', '2026-10-16 09:00:00', '2026-10-16 00:00:00'],
    'three days' => ['snooze3Days', '2026-10-18 09:00:00', '2026-10-18 00:00:00'],
    'one week' => ['snooze1Week', '2026-10-22 09:00:00', '2026-10-22 00:00:00'],
]);

test('the custom snooze shows the new due date and survives Save', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->callAction('snoozeCustom', ['due_at' => '2026-10-20 10:00:00'])
        ->assertHasNoActionErrors()
        ->assertSet('data.due_at', '2026-10-20 10:00:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-20 01:00:00');
});

test('Mark Done shows Done and survives Save', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->callAction('markDone')
        ->assertSet('data.status', TaskStatus::Done->value)
        ->call('save')
        ->assertHasNoFormErrors();

    $task = $this->task->fresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00');
});

test('an unsaved edit to another field is kept by a snooze and by Mark Done', function (string $action) {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->fillForm(['title' => 'Call back tomorrow'])
        ->callAction($action)
        ->assertSet('data.title', 'Call back tomorrow')
        ->call('save')
        ->assertHasNoFormErrors();

    $task = $this->task->fresh();

    expect($task->title)->toBe('Call back tomorrow');

    if ($action === 'markDone') {
        expect($task->status)->toBe(TaskStatus::Done);

        return;
    }

    expect($task->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-16 00:00:00');
})->with(['snooze1Day', 'markDone']);
