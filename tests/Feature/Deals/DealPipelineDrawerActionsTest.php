<?php

use App\Enums\ActivityType;
use App\Enums\Currency;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Support\MoneyLimit;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Eur]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'value' => 1234.5,
        'won_at' => null,
        'lost_at' => null,
        'position' => '1000.0000000000',
    ]);

    $this->openDrawer = fn (?Deal $deal = null): Testable => Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) ($deal ?? $this->deal)->id]);

    $this->mountChild = fn (Testable $component, string $name, ?Deal $deal = null): Testable => $component
        ->call('mountAction', $name, [], ['recordKey' => (string) ($deal ?? $this->deal)->id]);

    $this->childVisible = fn (Testable $component, string $name): bool => $component->instance()
        ->getMountedAction()->getModalAction($name)->isVisible();

    $this->asRole = function (OrganizationRole $role): void {
        $member = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($member, ['role' => $role->value]);
        $this->actingAs($member);
        Filament::setTenant($this->org);
    };

    $this->otherOrgDeal = function (): Deal {
        $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $otherContact = Contact::factory()->create(['organization_id' => $otherUser->personalOrganization()->id]);

        return Deal::factory()->create([
            'organization_id' => $otherUser->personalOrganization()->id,
            'contact_id' => $otherContact->id,
            'created_by' => $otherUser->id,
            'title' => 'Other Org Deal',
            'stage' => DealStage::Lead,
            'status' => DealStatus::Open,
        ]);
    };

});

afterEach(function () {
    Carbon::setTestNow();
});

test('who sees which quick action', function (OrganizationRole $role, bool $visible) {
    ($this->asRole)($role);

    $component = ($this->openDrawer)();

    foreach (['moveStage', 'logActivity', 'addTask', 'editDeal'] as $name) {
        expect(($this->childVisible)($component, $name))->toBe($visible, $name);
    }

    expect(($this->childVisible)($component, 'openFullPage'))->toBeTrue();
})->with([
    [OrganizationRole::Owner, true],
    [OrganizationRole::Admin, true],
    [OrganizationRole::Member, true],
    [OrganizationRole::Viewer, false],
]);

test('the footer lists the quick actions in order, the full page link last', function () {
    $extra = collect(($this->openDrawer)()->instance()->getMountedAction()->getExtraModalFooterActions())
        ->map(fn (Action $action): string => $action->getName())
        ->values()
        ->all();

    expect($extra)->toBe(['moveStage', 'logActivity', 'addTask', 'editDeal', 'openFullPage']);
});

test('move stage moves the card to the bottom of the column and updates the drawer', function () {
    $wonCard = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'stage' => DealStage::Won,
        'status' => DealStatus::Won,
        'won_at' => '2026-10-01 09:00:00',
        'position' => '5000.0000000000',
    ]);

    $component = ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->assertActionDataSet(['stage' => DealStage::Lead, 'from_stage' => 'lead'])
        ->fillForm(['stage' => DealStage::Won])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->stage)->toBe(DealStage::Won)
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and((float) $deal->position)->toBeGreaterThan((float) $wonCard->fresh()->position);

    $board = $component->instance()->getBoard();

    expect($board->getBoardRecords('won')->pluck('id'))->toContain($deal->id)
        ->and($board->getBoardRecords('lead')->pluck('id'))->not->toContain($deal->id);

    $component->assertSet('mountedActions.0.name', 'openDeal')
        ->assertMountedActionModalSee(['Won']);
    expect(count($component->instance()->mountedActions))->toBe(1);

    $component->assertNotified('Deal moved to Won');
});

test('reopening a won deal clears the won date', function () {
    $this->deal->forceFill(['stage' => DealStage::Won, 'status' => DealStatus::Won, 'won_at' => '2026-10-01 09:00:00'])->saveQuietly();

    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['stage' => DealStage::ProposalSent])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->stage)->toBe(DealStage::ProposalSent)
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull();
});

test('moving to the same stage writes nothing and sends no notification', function () {
    $before = $this->deal->fresh();

    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['stage' => DealStage::Lead])
        ->call('callMountedAction')
        ->assertNotNotified();

    $after = $this->deal->fresh();

    expect($after->updated_at->toDateTimeString())->toBe($before->updated_at->toDateTimeString())
        ->and($after->position)->toBe($before->position)
        ->and($after->stage)->toBe(DealStage::Lead);
});

test('a stale stage writes nothing and warns', function () {
    $component = ($this->mountChild)(($this->openDrawer)(), 'moveStage');

    DealStageMover::move($this->deal->fresh(), DealStage::Discovery);

    $component->fillForm(['stage' => DealStage::Won])
        ->call('callMountedAction')
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title('This deal was moved meanwhile')
                ->body('It is now in Discovery. Nothing was changed.')
        );

    $deal = $this->deal->fresh();

    expect($deal->stage)->toBe(DealStage::Discovery)
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull();
});

test('a forged stage outside the options is refused', function () {
    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['stage' => 'archived'])
        ->call('callMountedAction')
        ->assertHasFormErrors(['stage']);

    expect($this->deal->fresh()->stage)->toBe(DealStage::Lead);
});

test('log an activity from the drawer', function () {
    ($this->mountChild)(($this->openDrawer)(), 'logActivity')
        ->fillForm(['type' => ActivityType::Call, 'occurred_at' => '2026-10-09 10:00:00', 'subject' => 'Drawer call'])
        ->call('callMountedAction')
        ->assertHasNoFormErrors()
        ->assertSet('mountedActions.0.name', 'openDeal')
        ->assertMountedActionModalSee(['Call · Oct 9, 2026 · Drawer call']);

    $activity = Activity::query()->where('subject', 'Drawer call')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->deal_id)->toBe($this->deal->id)
        ->and($activity->contact_id)->toBe($this->contact->id);
});

test('add a task from the drawer', function () {
    $component = ($this->mountChild)(($this->openDrawer)(), 'addTask')
        ->assertActionDataSet(fn (array $state): bool => $state['contact_id'] === $this->contact->id
            && $state['deal_id'] === $this->deal->id
            && $state['contact_name'] === 'Jane Doe');

    $component->fillForm(['title' => 'Follow up'])
        ->call('callMountedAction')
        ->assertHasNoFormErrors()
        ->assertNotified('Task created successfully')
        ->assertMountedActionModalSee(['Follow up — due Oct 12, 2026']);

    $task = Task::query()->where('title', 'Follow up')->first();

    expect($task)->not->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->contact_id)->toBe($this->contact->id)
        ->and($task->deal_id)->toBe($this->deal->id)
        ->and($task->due_at->toDateTimeString())->toBe('2026-10-12 12:00:00');
});

test('add task and log activity are hidden for a deal without a live contact', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);

    $ghostContact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $ghost = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $ghostContact->id, 'title' => 'Ghost']);
    $ghostContact->delete();

    $soloDrawer = ($this->openDrawer)($solo);
    $ghostDrawer = ($this->openDrawer)($ghost);

    expect(($this->childVisible)($soloDrawer, 'addTask'))->toBeFalse()
        ->and(($this->childVisible)($soloDrawer, 'logActivity'))->toBeFalse()
        ->and(($this->childVisible)($ghostDrawer, 'addTask'))->toBeFalse()
        ->and(($this->childVisible)($soloDrawer, 'moveStage'))->toBeTrue()
        ->and(($this->childVisible)($soloDrawer, 'editDeal'))->toBeTrue();
});

test('the deal page quick task is not changed by the new factory', function () {
    expect(QuickTaskAction::makeForDeal()->getName())->toBe('addTask')
        ->and(QuickTaskAction::makeForDeal('other')->getName())->toBe('other');
});

test('edit from the drawer saves through the deal form and the stage rule', function () {
    $component = ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->assertActionDataSet(fn (array $state): bool => $state['title'] === 'Retainer' && DealStage::from($state['stage'] instanceof DealStage ? $state['stage']->value : $state['stage']) === DealStage::Lead)
        ->fillForm(['value' => 2000, 'stage' => DealStage::Lost])
        ->call('callMountedAction')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved')
        ->assertMountedActionModalSee(['€2,000.00', 'Lost']);

    $deal = $this->deal->fresh();

    expect((string) $deal->value)->toBe('2000.00')
        ->and($deal->stage)->toBe(DealStage::Lost)
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and($component->instance()->getBoard()->getBoardRecords('lost')->firstWhere('id', $deal->id)?->value)->toBe('2000.00')
        ->and(Livewire::test(DealPipeline::class)->html())->toContain('€2,000.00');
});

test('the edit form keeps the deal form rules', function (string $field, mixed $value, ?string $rule) {
    if ($field === 'contact_id') {
        $value = Contact::factory()->create()->id;
    }

    $before = $this->deal->fresh()->getAttributes();

    ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->fillForm([$field => $value])
        ->call('callMountedAction')
        ->assertHasFormErrors($rule === null ? [$field] : [$field => $rule]);

    expect($this->deal->fresh()->getAttributes())->toBe($before);
})->with([
    'title required' => ['title', '', 'required'],
    'value max' => ['value', 100000000, 'max'],
    'contact of another organization' => ['contact_id', null, null],
]);

test('a viewer forging a quick action changes nothing', function (string $action, array $data) {
    ($this->asRole)(OrganizationRole::Viewer);

    $before = [Deal::query()->count(), Task::query()->count(), Activity::query()->count(), $this->deal->fresh()->getAttributes()];

    $component = ($this->mountChild)(($this->openDrawer)(), $action);

    expect($component->instance()->mountedActions)->toHaveCount(1)
        ->and($component->instance()->mountedActions[0]['name'])->toBe('openDeal');

    $component->call('callMountedAction', $data);

    expect([Deal::query()->count(), Task::query()->count(), Activity::query()->count(), $this->deal->fresh()->getAttributes()])->toBe($before);
})->with([
    'moveStage' => ['moveStage', ['stage' => 'won', 'from_stage' => 'lead']],
    'editDeal' => ['editDeal', ['title' => 'Hacked', 'stage' => 'won']],
    'logActivity' => ['logActivity', ['type' => 'call', 'occurred_at' => '2026-10-09 10:00:00', 'deal_id' => 1]],
    'addTask' => ['addTask', ['title' => 'Forged', 'contact_id' => 1]],
]);

test("a child action pointed at another organization's deal changes nothing", function (string $action) {
    $other = ($this->otherOrgDeal)();
    $before = [$other->fresh()->getAttributes(), Task::query()->withoutGlobalScopes()->count(), Activity::query()->withoutGlobalScopes()->count()];

    $component = ($this->openDrawer)();
    $component->call('mountAction', $action, [], ['recordKey' => (string) $other->id]);

    expect($component->instance()->mountedActions)->toHaveCount(1)
        ->and($component->instance()->mountedActions[0]['name'])->toBe('openDeal');

    $component->call('callMountedAction', ['stage' => 'won', 'from_stage' => 'lead', 'title' => 'Hacked', 'type' => 'call', 'occurred_at' => '2026-10-09 10:00:00']);

    expect([$other->fresh()->getAttributes(), Task::query()->withoutGlobalScopes()->count(), Activity::query()->withoutGlobalScopes()->count()])->toBe($before);
})->with(['moveStage', 'editDeal', 'logActivity', 'addTask']);

test('a quick action mounted without the drawer does nothing', function (string $action) {
    $before = $this->deal->fresh()->getAttributes();

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', $action, [], ['recordKey' => (string) $this->deal->id])
        ->call('callMountedAction', ['stage' => 'won', 'from_stage' => 'lead', 'title' => 'Hacked']);

    expect($component->instance()->mountedActions)->toBe([])
        ->and($this->deal->fresh()->getAttributes())->toBe($before);
})->with(['moveStage', 'editDeal', 'logActivity', 'addTask']);

test('a deal deleted while a quick action form is open writes nothing and does not crash', function (string $action, array $data) {
    $component = ($this->mountChild)(($this->openDrawer)(), $action)->fillForm($data);

    $this->deal->delete();

    $before = [Task::query()->count(), Activity::query()->count()];

    $component->call('callMountedAction')->assertNotNotified();

    expect(Deal::query()->find($this->deal->id))->toBeNull()
        ->and([Task::query()->count(), Activity::query()->count()])->toBe($before);
})->with([
    'moveStage' => ['moveStage', ['stage' => DealStage::Won]],
    'editDeal' => ['editDeal', ['title' => 'Changed']],
    'logActivity' => ['logActivity', ['type' => ActivityType::Call, 'occurred_at' => '2026-10-09 10:00:00']],
    'addTask' => ['addTask', ['title' => 'Late task']],
]);

test("a mounted edit form whose record key is swapped to another organization's deal does not crash and changes nothing", function () {
    $other = ($this->otherOrgDeal)();
    $before = $other->fresh()->getAttributes();

    $component = ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->fillForm(['title' => 'Hacked']);

    $component->set('mountedActions.1.context.recordKey', (string) $other->id)
        ->call('callMountedAction');

    expect($other->fresh()->getAttributes())->toBe($before)
        ->and($this->deal->fresh()->title)->toBe('Retainer');
});

test('the moveCard drag path still moves the card', function () {
    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $this->deal->id, DealStage::Won->value);

    expect($this->deal->fresh()->status)->toBe(DealStatus::Won);
});

test('the edit form accepts the size limits and refuses one more', function () {
    ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->fillForm(['title' => str_repeat('a', 255), 'value' => MoneyLimit::MAX])
        ->call('callMountedAction')
        ->assertHasNoFormErrors();

    expect($this->deal->fresh()->title)->toBe(str_repeat('a', 255));

    ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->fillForm(['title' => str_repeat('b', 256)])
        ->call('callMountedAction')
        ->assertHasFormErrors(['title' => 'max']);

    expect($this->deal->fresh()->title)->toBe(str_repeat('a', 255));
});

test('the drawer task form refuses a forged contact or deal of another organization', function () {
    $other = ($this->otherOrgDeal)();

    ($this->mountChild)(($this->openDrawer)(), 'addTask')
        ->fillForm(['title' => 'Forged contact', 'contact_id' => $other->contact_id])
        ->call('callMountedAction')
        ->assertHasFormErrors(['contact_id']);

    ($this->mountChild)(($this->openDrawer)(), 'addTask')
        ->fillForm(['title' => 'Forged deal', 'deal_id' => $other->id])
        ->call('callMountedAction')
        ->assertHasFormErrors(['deal_id']);

    expect(Task::query()->withoutGlobalScopes()->whereIn('title', ['Forged contact', 'Forged deal'])->count())->toBe(0);
});
