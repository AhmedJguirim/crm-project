<?php

use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Schemas\DealDrawerInfolist;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 08:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->alice->personalOrganization();
    $this->org->update(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'owner_id' => $this->alice->id,
        'title' => 'Call back',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-10-12 07:30:00',
    ]);
});

afterEach(function () {
    Filament::setTenant(null);
    Carbon::setTestNow();
});

test('the panel timezone follows the tenant and never leaks', function () {
    expect(FilamentTimezone::get())->toBe('Asia/Tokyo');

    $ny = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $ny->update(['timezone' => 'America/New_York']);
    Filament::setTenant($ny);

    expect(FilamentTimezone::get())->toBe('America/New_York');

    Filament::setTenant(null);

    expect(FilamentTimezone::get())->toBe('UTC');
});

test('an invalid stored timezone falls back to UTC', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();
    Filament::setTenant($this->org->fresh());

    expect(FilamentTimezone::get())->toBe('UTC');
});

test('LocalTime prints a moment in the given or the panel timezone and passes null through', function () {
    $moment = CarbonImmutable::parse('2026-10-24 22:30:00', 'UTC');

    expect(LocalTime::format($moment, 'M j, Y g:i A', 'Europe/Paris'))->toBe('Oct 25, 2026 12:30 AM')
        ->and(LocalTime::format(CarbonImmutable::parse('2026-10-25 01:30:00', 'UTC'), 'M j, Y g:i A', 'Europe/Paris'))->toBe('Oct 25, 2026 2:30 AM')
        ->and(LocalTime::format(CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC'), 'M j, Y'))->toBe('Oct 12, 2026')
        ->and(LocalTime::format($moment, 'M j, Y g:i A'))->toBe('Oct 25, 2026 7:30 AM')
        ->and(LocalTime::format(null, 'M j, Y'))->toBeNull()
        ->and($moment->format('H:i'))->toBe('22:30');
});

test('the tasks table shows local time', function () {
    Livewire::test(ListTasks::class)
        ->assertSee('Oct 12, 2026 4:30 PM')
        ->assertDontSee('Oct 12, 2026 7:30 AM');
});

test('the task form shows and saves local time', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->assertSet('data.due_at', '2026-10-12 16:30:00')
        ->fillForm(['due_at' => '2026-10-15 09:00:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-15 00:00:00');
});

test('saving a task without touching the due date keeps it', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->fillForm(['title' => 'Call back later'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 07:30:00');
});

test('date-only values do not move', function () {
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'title' => 'Retainer',
        'expected_close_date' => '2026-10-12',
    ]);

    Livewire::test(ListDeals::class)->assertSee('Oct 12, 2026')->assertDontSee('Oct 11, 2026');
});

test('the board drawer prints local dates', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->alice->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
    ]);
    Task::factory()->create([
        'organization_id' => $this->org->id,
        'deal_id' => $deal->id,
        'created_by' => $this->alice->id,
        'title' => 'Prep',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-10-11 23:30:00',
    ]);
    Activity::factory()->type(ActivityType::Call)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->alice->id,
        'deal_id' => $deal->id,
        'occurred_at' => '2026-10-11 23:30:00',
        'subject' => 'Kick-off',
    ]);

    expect(DealDrawerInfolist::nextTaskLines($deal))->toBe(['Prep — due Oct 12, 2026'])
        ->and(DealDrawerInfolist::recentActivityLines($deal))->toBe(['Call · Oct 12, 2026 · Kick-off']);
});

test('the deal page and the activity feeds print local time', function () {
    $this->org->update(['timezone' => 'America/New_York']);
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->alice->id,
        'stage' => DealStage::Won,
        'status' => DealStatus::Won,
        'won_at' => '2026-10-12 03:00:00',
    ]);
    $lost = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'stage' => DealStage::Lost,
        'status' => DealStatus::Lost,
        'lost_at' => '2026-10-12 03:00:00',
    ]);
    Activity::factory()->type(ActivityType::Call)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->alice->id,
        'deal_id' => $deal->id,
        'occurred_at' => '2026-10-12 03:00:00',
        'subject' => 'Kick-off',
    ]);

    Livewire::test(DealDetailsWidget::class, ['record' => $deal])->assertSee('Oct 11, 2026 at 11:00 PM')->assertDontSee('Oct 12, 2026 at 3:00 AM');
    Livewire::test(DealDetailsWidget::class, ['record' => $lost])->assertSee('Oct 11, 2026 at 11:00 PM');
    Livewire::test(DealActivityFeed::class, ['record' => $deal])->assertSee('Oct 11, 2026 at 11:00 PM');
    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])->assertSee('Oct 11, 2026 at 11:00 PM');
    Livewire::test(RecentActivitiesWidget::class)->assertSee('Oct 11, 2026 11:00 PM');
});

test('the contact page prints the created date in local time', function () {
    $this->contact->forceFill(['created_at' => '2026-10-11 23:30:00'])->saveQuietly();

    Livewire::test(ContactDetailsWidget::class, ['record' => $this->contact])->assertSee('Oct 12, 2026');
});

test('notifications use the organization timezone whatever the tenant is', function (?string $otherTenant) {
    if ($otherTenant === null) {
        Filament::setTenant(null);
    } else {
        $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
        $other->update(['timezone' => $otherTenant]);
        Filament::setTenant($other);
    }

    $task = $this->task->fresh();

    $overdue = new TaskOverdueNotification($task, $this->org, 'key');
    $dueSoon = new TaskDueSoonNotification($task, $this->org, 'key');
    $digest = new TaskDailyDigestNotification($this->org, collect([$task]), collect([$task]));

    expect($overdue->toDatabase($this->alice)['body'])->toBe('Call back is overdue since Oct 12, 2026 4:30 PM.')
        ->and($dueSoon->toDatabase($this->alice)['body'])->toBe('Call back is due on Oct 12, 2026 4:30 PM.')
        ->and($overdue->toMail($this->alice)->introLines)->toContain('Due date: Oct 12, 2026 4:30 PM')
        ->and($dueSoon->toMail($this->alice)->introLines)->toContain('Due date: Oct 12, 2026 4:30 PM')
        ->and($digest->toMail($this->alice)->introLines)
        ->toContain('- OVERDUE: Call back (Oct 12, 2026 4:30 PM)')
        ->toContain('- TODAY: Call back (Oct 12, 2026 4:30 PM)');
})->with(['no tenant' => [null], 'a New York tenant' => ['America/New_York']]);

test('an organization with an invalid timezone gets UTC in its notifications', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->saveQuietly();
    $org = $this->org->fresh();
    $task = $this->task->fresh();

    $overdue = new TaskOverdueNotification($task, $org, 'key');
    $dueSoon = new TaskDueSoonNotification($task, $org, 'key');
    $digest = new TaskDailyDigestNotification($org, collect([$task]), collect());

    expect($overdue->toDatabase($this->alice)['body'])->toBe('Call back is overdue since Oct 12, 2026 7:30 AM.')
        ->and($dueSoon->toDatabase($this->alice)['body'])->toBe('Call back is due on Oct 12, 2026 7:30 AM.')
        ->and($overdue->toMail($this->alice)->introLines)->toContain('Due date: Oct 12, 2026 7:30 AM')
        ->and($dueSoon->toMail($this->alice)->introLines)->toContain('Due date: Oct 12, 2026 7:30 AM')
        ->and($digest->toMail($this->alice)->introLines)->toContain('- OVERDUE: Call back (Oct 12, 2026 7:30 AM)');
});

test('UTC organizations are unchanged', function () {
    $this->org->update(['timezone' => 'UTC']);

    Livewire::test(ListTasks::class)->assertSee('Oct 12, 2026 7:30 AM');
});

test('the custom snooze in the tasks table accepts a future local time west of UTC', function () {
    $this->org->update(['timezone' => 'America/New_York']);
    Filament::setTenant($this->org->fresh());

    Livewire::test(ListTasks::class)
        ->callTableAction('snoozeCustom', $this->task, ['due_at' => '2026-10-12 06:00:00'])
        ->assertHasNoTableActionErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 10:00:00');
});

test('the custom snooze in the tasks table refuses a past local time east of UTC', function () {
    Livewire::test(ListTasks::class)
        ->callTableAction('snoozeCustom', $this->task, ['due_at' => '2026-10-12 10:00:00'])
        ->assertHasTableActionErrors(['due_at' => "The new due date can't be in the past."]);

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 07:30:00');
});

test('the custom snooze on the edit page accepts a future local time west of UTC', function () {
    $this->org->update(['timezone' => 'America/New_York']);
    Filament::setTenant($this->org->fresh());

    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->callAction('snoozeCustom', ['due_at' => '2026-10-12 06:00:00'])
        ->assertHasNoActionErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 10:00:00');
});

test('the custom snooze on the edit page refuses a past local time east of UTC', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->callAction('snoozeCustom', ['due_at' => '2026-10-12 10:00:00'])
        ->assertHasActionErrors(['due_at' => "The new due date can't be in the past."]);

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 07:30:00');
});

test('the custom snooze picker has the start of the local day as its minimum', function (string $place) {
    $component = Livewire::test($place === 'edit page' ? EditTask::class : ListTasks::class, $place === 'edit page' ? ['record' => $this->task->id] : []);

    $place === 'edit page' ? $component->mountAction('snoozeCustom') : $component->mountTableAction('snoozeCustom', $this->task);

    $component->assertSchemaComponentExists('due_at', 'mountedActionSchema0', fn (DateTimePicker $field): bool => $field->getMinDate() === '2026-10-12 00:00:00');
})->with(['edit page', 'tasks table']);

test('the custom snooze minimum follows the local day, not the UTC day', function () {
    Carbon::setTestNow('2026-10-12 20:00:00');

    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->mountAction('snoozeCustom')
        ->assertSchemaComponentExists('due_at', 'mountedActionSchema0', fn (DateTimePicker $field): bool => $field->getMinDate() === '2026-10-13 00:00:00');
});

test('the custom snooze accepts any later time today', function (string $place) {
    $dueAt = '2026-10-12 23:00:00';

    if ($place === 'edit page') {
        Livewire::test(EditTask::class, ['record' => $this->task->id])->callAction('snoozeCustom', ['due_at' => $dueAt])->assertHasNoActionErrors();
    } else {
        Livewire::test(ListTasks::class)->callTableAction('snoozeCustom', $this->task, ['due_at' => $dueAt])->assertHasNoTableActionErrors();
    }

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 14:00:00');
})->with(['edit page', 'tasks table']);

test('the custom snooze refuses a time earlier today with the message', function (string $place) {
    $dueAt = '2026-10-12 16:59:00';

    if ($place === 'edit page') {
        Livewire::test(EditTask::class, ['record' => $this->task->id])->callAction('snoozeCustom', ['due_at' => $dueAt])->assertHasActionErrors(['due_at' => "The new due date can't be in the past."]);
    } else {
        Livewire::test(ListTasks::class)->callTableAction('snoozeCustom', $this->task, ['due_at' => $dueAt])->assertHasTableActionErrors(['due_at' => "The new due date can't be in the past."]);
    }

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 07:30:00');
})->with(['edit page', 'tasks table']);

test('the custom snooze accepts the exact current local minute', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->id])
        ->callAction('snoozeCustom', ['due_at' => '2026-10-12 17:00:00'])
        ->assertHasNoActionErrors();

    expect($this->task->fresh()->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00');
});

test('LocalTime gives the local day and periods as UTC bounds and as dates', function (string $utcNow, string $timezone, string $unit, string $startUtc, string $endUtc, string $startDate, string $endDate) {
    $this->travelTo(CarbonImmutable::parse($utcNow, 'UTC'));

    [$start, $end] = LocalTime::period($unit, $timezone);

    expect($start->format('Y-m-d H:i:s'))->toBe($startUtc)
        ->and($end->format('Y-m-d H:i:s'))->toBe($endUtc)
        ->and($start->timezone->getName())->toBe('UTC')
        ->and($end->timezone->getName())->toBe('UTC')
        ->and(LocalTime::periodDates($unit, $timezone))->toBe([$startDate, $endDate]);
})->with([
    'Tokyo day' => ['2026-10-12 23:30:00', 'Asia/Tokyo', 'day', '2026-10-12 15:00:00', '2026-10-13 14:59:59', '2026-10-13', '2026-10-13'],
    'New York week' => ['2026-10-12 02:00:00', 'America/New_York', 'week', '2026-10-05 04:00:00', '2026-10-12 03:59:59', '2026-10-05', '2026-10-11'],
    'Paris month' => ['2026-10-31 23:30:00', 'Europe/Paris', 'month', '2026-10-31 23:00:00', '2026-11-30 22:59:59', '2026-11-01', '2026-11-30'],
    'Paris year' => ['2026-12-31 23:30:00', 'Europe/Paris', 'year', '2026-12-31 23:00:00', '2027-12-31 22:59:59', '2027-01-01', '2027-12-31'],
    'Paris day with a DST change' => ['2026-10-25 12:00:00', 'Europe/Paris', 'day', '2026-10-24 22:00:00', '2026-10-25 22:59:59', '2026-10-25', '2026-10-25'],
    'UTC week' => ['2026-10-12 23:30:00', 'UTC', 'week', '2026-10-12 00:00:00', '2026-10-18 23:59:59', '2026-10-12', '2026-10-18'],
]);

test('LocalTime refuses an unknown period unit', function () {
    LocalTime::period('fortnight', 'UTC');
})->throws(UnhandledMatchError::class);

test('LocalTime::todayDate() and now() follow the panel timezone by default', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00', 'UTC'));

    expect(LocalTime::todayDate())->toBe('2026-10-13')
        ->and(LocalTime::now()->timezone->getName())->toBe('Asia/Tokyo');

    Filament::setTenant(null);

    expect(LocalTime::todayDate())->toBe('2026-10-12');
});
