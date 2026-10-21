<?php

use App\Enums\DealAttentionReason;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Widgets\DealsNeedingAttentionWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Services\Reports\DealsNeedingAttention;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->me = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->me->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->colleague = User::factory()->onboardingCompleted()->create();
    $this->actingAs($this->me);
    Filament::setTenant($this->org->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->makeTeam = fn () => $this->org->members()->attach($this->colleague, ['role' => OrganizationRole::Member->value]);

    /**
     * A deal of the organization; healthy by default (created 2026-10-15, closing 2026-11-30, one pending task, one
     * activity on 2026-10-19). Options: created, close, stage, owner, task (pending|overdue|done|deleted|none),
     * activity (a UTC datetime, or null for none).
     */
    $this->attentionDeal = function (string $title, array $options = []): Deal {
        $deal = Deal::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->me->id,
            'owner_id' => ($options['owner'] ?? null)?->id,
            'contact_id' => $this->contact->id,
            'title' => $title,
            'expected_close_date' => array_key_exists('close', $options) ? $options['close'] : '2026-11-30',
            'created_at' => CarbonImmutable::parse($options['created'] ?? '2026-10-15 08:00:00', 'UTC'),
            ...DealStageMover::attributesFor(pipelineStageNamed($this->org->id, $options['stage'] ?? 'Lead')),
        ]);

        $task = $options['task'] ?? 'pending';

        if ($task !== 'none') {
            $row = Task::factory()->create([
                'organization_id' => $this->org->id,
                'created_by' => $this->me->id,
                'contact_id' => $this->contact->id,
                'deal_id' => $deal->id,
                'due_at' => $task === 'overdue' ? '2026-10-10 09:00:00' : '2026-10-25 09:00:00',
                'status' => $task === 'done' ? TaskStatus::Done : TaskStatus::Pending,
                'completed_at' => $task === 'done' ? now() : null,
            ]);

            if ($task === 'deleted') {
                $row->delete();
            }
        }

        $activity = array_key_exists('activity', $options) ? $options['activity'] : '2026-10-19 10:00:00';

        if ($activity !== null) {
            Activity::factory()->create([
                'organization_id' => $this->org->id,
                'contact_id' => $this->contact->id,
                'user_id' => $this->me->id,
                'deal_id' => $deal->id,
                'occurred_at' => CarbonImmutable::parse($activity, 'UTC'),
            ]);
        }

        return $deal;
    };

    $this->widgetReasons = function (string $title, array $pageFilters = []): ?array {
        $record = Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => $pageFilters])
            ->instance()
            ->getTableRecords()
            ->firstWhere('title', $title);

        return $record === null ? null : array_map(fn (DealAttentionReason $reason): string => $reason->value, DealsNeedingAttention::reasonsFor($record));
    };

    $this->tabTitles = fn (): array => Livewire::test(ListDeals::class)
        ->set('activeTab', 'attention')
        ->instance()
        ->getTableRecords()
        ->pluck('title')
        ->all();
});

test('a healthy deal is not listed', function () {
    ($this->attentionDeal)('Healthy');

    Livewire::test(DealsNeedingAttentionWidget::class)
        ->assertSuccessful()
        ->assertSee('No deals need attention')
        ->assertSee('Every open deal has a next step and recent activity, and none is late or stuck in its stage.')
        ->assertDontSee('Healthy');

    expect(($this->tabTitles)())->toBe([]);
});

test('each reason on its own', function (string $title, array $options, DealAttentionReason $reason) {
    ($this->attentionDeal)('Healthy');
    ($this->attentionDeal)($title, $options);

    expect(($this->widgetReasons)($title))->toBe([$reason->value])
        ->and(($this->widgetReasons)('Healthy'))->toBeNull()
        ->and(($this->tabTitles)())->toBe([$title]);

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee($title)->assertSee($reason->getLabel());
})->with([
    'late' => ['Late', ['close' => '2026-10-19'], DealAttentionReason::PastCloseDate],
    'task done' => ['NoTask', ['task' => 'done'], DealAttentionReason::NoNextStep],
    'task deleted' => ['Deleted', ['task' => 'deleted'], DealAttentionReason::NoNextStep],
    'quiet' => ['Quiet', ['created' => '2026-10-01 08:00:00', 'activity' => '2026-10-06 07:59:59'], DealAttentionReason::NoRecentActivity],
    'stuck' => ['Stuck', ['created' => '2026-09-20 07:59:59'], DealAttentionReason::StuckInStage],
]);

test('boundaries are not flagged', function (string $title, array $options) {
    ($this->attentionDeal)($title, $options);

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('No deals need attention');

    expect(($this->tabTitles)())->toBe([]);
})->with([
    'closing today' => ['Today', ['close' => '2026-10-20']],
    'no date' => ['NoDate', ['close' => null]],
    'overdue task still counts' => ['OverdueTsk', ['task' => 'overdue']],
    'activity at the cutoff' => ['Edge', ['created' => '2026-10-01 08:00:00', 'activity' => '2026-10-06 08:00:00']],
    'new deal without activity' => ['NewDeal', ['created' => '2026-10-10 08:00:00', 'activity' => null]],
    'stage at the cutoff' => ['StageEdge', ['created' => '2026-09-20 08:00:00']],
    'future activity' => ['Future', ['created' => '2026-10-01 08:00:00', 'activity' => '2026-10-25 09:00:00']],
]);

test('local today decides past close date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 23:30:00', 'UTC'));
    ($this->attentionDeal)('Late', ['close' => '2026-10-19', 'created' => '2026-10-19 08:00:00', 'activity' => '2026-10-19 09:00:00']);

    expect(($this->widgetReasons)('Late'))->toBe([DealAttentionReason::PastCloseDate->value]);
});

test('a deal moved recently is not stuck even if created long ago', function () {
    $deal = ($this->attentionDeal)('Moved', ['created' => '2026-08-01 08:00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00:00', 'UTC'));
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('No deals need attention');
    expect(($this->tabTitles)())->toBe([]);
});

test('a deal moved back out of a stage long ago is stuck in its new stage only by the latest change', function () {
    $deal = ($this->attentionDeal)('MovedOld', ['created' => '2026-08-01 08:00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC'));
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00:00', 'UTC'));
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Lead'));
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

    expect(($this->widgetReasons)('MovedOld'))->toBeNull()
        ->and(($this->tabTitles)())->toBe([]);
});

test('no fallback when the history has no row for the current stage', function () {
    $deal = ($this->attentionDeal)('NoHistory', ['created' => '2026-08-01 08:00:00']);
    DealStageChange::query()->where('deal_id', $deal->id)->delete();

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('No deals need attention');
    expect(($this->tabTitles)())->toBe([]);
});

test('a latest stage change into another stage does not make the deal stuck', function () {
    $deal = ($this->attentionDeal)('Drifted', ['created' => '2026-08-01 08:00:00']);
    Deal::query()->whereKey($deal->id)->update(['pipeline_stage_id' => pipelineStageNamed($this->org->id, 'Discovery')->id]);

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('No deals need attention');
    expect(($this->tabTitles)())->toBe([]);
});

test('a new deal without activity only gets the reasons that apply', function () {
    ($this->attentionDeal)('Fresh', ['created' => '2026-10-10 08:00:00', 'task' => 'none', 'activity' => null]);

    expect(($this->widgetReasons)('Fresh'))->toBe([DealAttentionReason::NoNextStep->value]);
});

test('all reasons at once, in enum order', function () {
    ($this->attentionDeal)('Everything', ['created' => '2026-08-01 08:00:00', 'close' => '2026-10-01', 'task' => 'none', 'activity' => null]);

    expect(($this->widgetReasons)('Everything'))->toBe(array_map(fn (DealAttentionReason $reason): string => $reason->value, DealAttentionReason::cases()));

    Livewire::test(DealsNeedingAttentionWidget::class)
        ->assertSeeInOrder(['Everything', 'Past close date', 'No next step', 'No activity for 14 days', 'Same stage for 30+ days']);
});

test('won and lost deals are never listed', function (string $stage) {
    ($this->attentionDeal)('Closed', ['stage' => $stage, 'close' => '2026-10-01', 'task' => 'none']);

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('No deals need attention');
    expect(($this->tabTitles)())->toBe([]);
})->with(['Won', 'Lost']);

test('the owner filter', function () {
    ($this->makeTeam)();
    ($this->attentionDeal)('Mine', ['owner' => $this->me, 'task' => 'none']);
    ($this->attentionDeal)('Theirs', ['owner' => $this->colleague, 'task' => 'none']);

    Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => ['owner' => (string) $this->colleague->id]])
        ->assertSee('Theirs')->assertDontSee('Mine');

    Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => ['owner' => '999999']])
        ->assertSee('Theirs')->assertSee('Mine');

    Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => ['owner' => 'me']])
        ->assertSee('Mine')->assertDontSee('Theirs');

    Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => ['owner' => ['1 OR 1=1']]])
        ->assertSee('Mine')->assertSee('Theirs');
});

test('the period filter is ignored', function () {
    ($this->attentionDeal)('NoTask', ['task' => 'none']);

    Livewire::test(DealsNeedingAttentionWidget::class, ['pageFilters' => ['period' => 'last_month']])->assertSee('NoTask');
});

test('another organization is kept out', function () {
    $other = Organization::factory()->create();
    $foreign = Deal::factory()->create([
        'organization_id' => $other->id,
        'title' => 'Foreign',
        'expected_close_date' => '2026-10-01',
        ...DealStageMover::attributesFor(pipelineStageNamed($other->id, 'Lead')),
    ]);
    Task::factory()->create(['organization_id' => $other->id, 'deal_id' => $foreign->id, 'status' => TaskStatus::Pending]);
    ($this->attentionDeal)('Mine', ['task' => 'none']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('Mine')->assertDontSee('Foreign');

    $sql = implode("\n", $queries);

    expect(($this->tabTitles)())->toBe(['Mine'])
        ->and($sql)->toContain('"tasks"."organization_id" =')
        ->and($sql)->toContain('"contact_activities"."organization_id" =')
        ->and($sql)->toContain('"deal_stage_changes"."organization_id" =');
});

test('every role sees the widget', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    ($this->attentionDeal)('NoTask', ['task' => 'none']);

    $this->actingAs($user);
    Filament::setTenant($this->org);

    Livewire::test(DealsNeedingAttentionWidget::class)->assertSee('NoTask');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('titles are escaped', function () {
    ($this->attentionDeal)('<script>alert(1)</script>', ['task' => 'none']);

    $html = Livewire::test(DealsNeedingAttentionWidget::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

test('widget texts and link', function () {
    $component = Livewire::test(DealsNeedingAttentionWidget::class)
        ->assertSee('Deals needing attention')
        ->assertSee('Open deals past their close date, without a pending task, without activity for 14 days, or in the same stage for over 30 days.');

    $component->assertActionHasUrl(TestAction::make('seeAll')->table(), DealResource::getUrl('index', ['tab' => 'attention']))
        ->assertActionHasLabel(TestAction::make('seeAll')->table(), 'See all deals needing attention');
});

test('the deals list tabs', function () {
    ($this->attentionDeal)('NoTask', ['task' => 'none']);
    ($this->attentionDeal)('Healthy');

    $list = Livewire::test(ListDeals::class);

    expect(array_keys($list->instance()->getTabs()))->toBe(['all', 'mine', 'attention'])
        ->and(collect($list->instance()->getTabs())->map(fn ($tab) => $tab->getLabel())->values()->all())->toBe(['All deals', 'My deals', 'Needs attention'])
        ->and($list->instance()->activeTab)->toBe('all');

    $list->assertCanSeeTableRecords(Deal::query()->get())
        ->set('activeTab', 'attention')
        ->assertSee('NoTask')
        ->assertDontSee('Healthy');
});

test('a forged tab key falls back without an error', function () {
    ($this->attentionDeal)('NoTask', ['task' => 'none']);

    Livewire::withQueryParams(['tab' => 'nope'])->test(ListDeals::class)->assertSuccessful();
});

test('rendering runs no query per row', function () {
    $countQueries = function (): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        Livewire::test(DealsNeedingAttentionWidget::class)->assertSuccessful();

        return $count;
    };

    ($this->attentionDeal)('Owned 0', ['task' => 'none', 'owner' => $this->me]);
    $countQueries();
    $one = $countQueries();

    foreach (range(1, 3) as $i) {
        ($this->attentionDeal)("Owned {$i}", ['task' => 'none', 'owner' => $this->me]);
    }

    expect($countQueries())->toBe($one);
});
