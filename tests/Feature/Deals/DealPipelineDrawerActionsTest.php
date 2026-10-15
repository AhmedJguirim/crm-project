<?php

use App\Enums\ActivityType;
use App\Enums\Currency;
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
use Illuminate\Support\Number;
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

    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
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

    $this->stageId = fn (string $stage): int => pipelineStageNamed($this->org->id, $stage)->id;

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

        return Deal::factory()->inStage('Lead')->create([
            'organization_id' => $otherUser->personalOrganization()->id,
            'contact_id' => $otherContact->id,
            'created_by' => $otherUser->id,
            'title' => 'Other Org Deal',
            'status' => DealStatus::Open,
        ]);
    };

    $this->cardElement = function (Testable $component, Deal $deal): ?DOMElement {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR);

        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->getAttribute('data-card-id') === (string) $deal->id) {
                return $element;
            }
        }

        return null;
    };

    $this->cardColumn = function (Testable $component, Deal $deal): ?string {
        $element = ($this->cardElement)($component, $deal)?->parentNode;

        while ($element instanceof DOMElement) {
            if ($element->hasAttribute('data-column-id')) {
                return $element->getAttribute('data-column-id');
            }

            $element = $element->parentNode;
        }

        return null;
    };

    $this->cardText = fn (Testable $component, Deal $deal): ?string => ($this->cardElement)($component, $deal)?->textContent;

    $this->drawerText = function (Testable $component): string {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR);

        $cards = [];

        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('data-card-id')) {
                $cards[] = $element;
            }
        }

        foreach ($cards as $card) {
            $card->parentNode?->removeChild($card);
        }

        return trim(preg_replace('/\s+/', ' ', $dom->textContent));
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
    $wonCard = Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'status' => DealStatus::Won,
        'won_at' => '2026-10-01 09:00:00',
        'position' => '5000.0000000000',
    ]);

    $component = ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->assertActionDataSet(['pipeline_stage_id' => ($this->stageId)('Lead'), 'from_pipeline_stage_id' => ($this->stageId)('Lead')])
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Won')])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->pipelineStage->name)->toBe('Won')
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and((float) $deal->position)->toBeGreaterThan((float) $wonCard->fresh()->position);

    $board = $component->instance()->getBoard();

    expect($board->getBoardRecords((string) ($this->stageId)('Won'))->pluck('id'))->toContain($deal->id)
        ->and($board->getBoardRecords((string) ($this->stageId)('Lead'))->pluck('id'))->not->toContain($deal->id);

    $component->assertSet('mountedActions.0.name', 'openDeal');
    expect(count($component->instance()->mountedActions))->toBe(1)
        ->and($component->instance()->getMountedAction()->getRecord()->pipelineStage->name)->toBe('Won')
        ->and(($this->drawerText)($component))->toContain('Expected close')->toContain('Won');

    $component->assertNotified('Deal moved to Won');
});

test('reopening a won deal clears the won date', function () {
    $this->deal->forceFill(['pipeline_stage_id' => ($this->stageId)('Won'), 'status' => DealStatus::Won, 'won_at' => '2026-10-01 09:00:00'])->saveQuietly();

    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Proposal Sent')])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->pipelineStage->name)->toBe('Proposal Sent')
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull();
});

test('moving to the same stage writes nothing and sends no notification', function () {
    $before = $this->deal->fresh();

    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Lead')])
        ->call('callMountedAction')
        ->assertNotNotified();

    $after = $this->deal->fresh();

    expect($after->updated_at->toDateTimeString())->toBe($before->updated_at->toDateTimeString())
        ->and($after->position)->toBe($before->position)
        ->and($after->pipelineStage->name)->toBe('Lead');
});

test('a stale stage writes nothing and warns', function () {
    $component = ($this->mountChild)(($this->openDrawer)(), 'moveStage');

    DealStageMover::move($this->deal->fresh(), pipelineStageNamed($this->deal->organization_id, 'Discovery'));

    $component->fillForm(['pipeline_stage_id' => ($this->stageId)('Won')])
        ->call('callMountedAction')
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title('This deal was moved meanwhile')
                ->body('It is now in Discovery. Nothing was changed.')
        );

    $deal = $this->deal->fresh();

    expect($deal->pipelineStage->name)->toBe('Discovery')
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull();
});

test('a forged stage outside the options is refused', function () {
    ($this->mountChild)(($this->openDrawer)(), 'moveStage')
        ->fillForm(['pipeline_stage_id' => 'archived'])
        ->call('callMountedAction')
        ->assertHasFormErrors(['pipeline_stage_id']);

    expect($this->deal->fresh()->pipelineStage->name)->toBe('Lead');
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
    $hasPrefill = function (array $state): array {
        expect($state['contact_id'])->toEqual($this->contact->id)
            ->and($state['deal_id'])->toEqual($this->deal->id)
            ->and($state['contact_name'])->toBe('Jane Doe');

        return [];
    };

    $component = ($this->mountChild)(($this->openDrawer)(), 'addTask')
        ->assertActionDataSet($hasPrefill);

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
    $hasPrefill = function (array $state): array {
        expect($state['title'])->toBe('Retainer')
            ->and((int) $state['pipeline_stage_id'])->toBe(pipelineStageNamed($this->deal->organization_id, 'Lead')->id)
            ->and((float) $state['value'])->toBe(1234.5);

        return [];
    };

    $component = ($this->mountChild)(($this->openDrawer)(), 'editDeal')
        ->assertActionDataSet($hasPrefill)
        ->fillForm(['value' => 2000, 'pipeline_stage_id' => pipelineStageNamed($this->deal->organization_id, 'Lost')->id])
        ->call('callMountedAction')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved');

    expect((string) $component->instance()->getMountedAction()->getRecord()->value)->toBe('2000.00')
        ->and($component->instance()->getMountedAction()->getRecord()->pipelineStage->name)->toBe('Lost')
        ->and(($this->drawerText)($component))->toContain('Expected close')->toContain(Number::currency(2000, 'EUR'))->toContain('Lost')
        ->and($component->html())->not->toContain('€1,234.50');

    $deal = $this->deal->fresh();

    expect((string) $deal->value)->toBe('2000.00')
        ->and($deal->pipelineStage->name)->toBe('Lost')
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and($component->instance()->getBoard()->getBoardRecords((string) ($this->stageId)('Lost'))->firstWhere('id', $deal->id)?->value)->toBe('2000.00')
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
    'moveStage' => ['moveStage', ['pipeline_stage_id' => 1, 'from_pipeline_stage_id' => 1]],
    'editDeal' => ['editDeal', ['title' => 'Hacked', 'pipeline_stage_id' => 999999999]],
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

    $component->call('callMountedAction', ['pipeline_stage_id' => 999999999, 'from_pipeline_stage_id' => 1, 'title' => 'Hacked', 'type' => 'call', 'occurred_at' => '2026-10-09 10:00:00']);

    expect([$other->fresh()->getAttributes(), Task::query()->withoutGlobalScopes()->count(), Activity::query()->withoutGlobalScopes()->count()])->toBe($before);
})->with(['moveStage', 'editDeal', 'logActivity', 'addTask']);

test('a quick action mounted without the drawer does nothing', function (string $action) {
    $before = $this->deal->fresh()->getAttributes();

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', $action, [], ['recordKey' => (string) $this->deal->id])
        ->call('callMountedAction', ['pipeline_stage_id' => 999999999, 'from_pipeline_stage_id' => 1, 'title' => 'Hacked']);

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
    'moveStage' => ['moveStage', ['pipeline_stage_id' => 1]],
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
        ->call('moveCard', (string) $this->deal->id, (string) ($this->stageId)('Won'));

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

test('move stage re-renders the card in its new column while the drawer stays open', function () {
    $component = ($this->openDrawer)();

    expect(($this->cardColumn)($component, $this->deal))->toBe((string) ($this->stageId)('Lead'));

    ($this->mountChild)($component, 'moveStage')
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Discovery')])
        ->call('callMountedAction')
        ->assertNotified('Deal moved to Discovery')
        ->assertSet('mountedActions.0.name', 'openDeal');

    expect(($this->cardColumn)($component, $this->deal))->toBe((string) ($this->stageId)('Discovery'))
        ->and(count($component->instance()->mountedActions))->toBe(1)
        ->and(($this->drawerText)($component))->toContain('Expected close')->toContain('Discovery');
});

test('edit re-renders the card with its new values while the drawer stays open', function () {
    $component = ($this->openDrawer)();

    expect(($this->cardText)($component, $this->deal))->toContain('€1,234.50');

    ($this->mountChild)($component, 'editDeal')
        ->fillForm(['value' => 32000, 'pipeline_stage_id' => pipelineStageNamed($this->deal->organization_id, 'Proposal Sent')->id])
        ->call('callMountedAction')
        ->assertNotified('Saved')
        ->assertSet('mountedActions.0.name', 'openDeal');

    expect(($this->cardColumn)($component, $this->deal))->toBe((string) ($this->stageId)('Proposal Sent'))
        ->and(($this->cardText)($component, $this->deal))->toContain(Number::currency(32000, 'EUR'))
        ->and(($this->cardText)($component, $this->deal))->not->toContain('€1,234.50')
        ->and(count($component->instance()->mountedActions))->toBe(1)
        ->and(($this->drawerText)($component))->toContain('Expected close')->toContain('Proposal Sent');
});

test('a stale move re-renders the board with the deal where it really is', function () {
    $component = ($this->mountChild)(($this->openDrawer)(), 'moveStage');

    expect(($this->cardColumn)($component, $this->deal))->toBe((string) ($this->stageId)('Lead'));

    DealStageMover::move($this->deal->fresh(), pipelineStageNamed($this->deal->organization_id, 'Negotiating'));

    $component->fillForm(['pipeline_stage_id' => ($this->stageId)('Discovery')])
        ->call('callMountedAction')
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title('This deal was moved meanwhile')
                ->body('It is now in Negotiating. Nothing was changed.')
        );

    expect(($this->cardColumn)($component, $this->deal))->toBe((string) ($this->stageId)('Negotiating'))
        ->and($this->deal->fresh()->pipelineStage->name)->toBe('Negotiating');
});
