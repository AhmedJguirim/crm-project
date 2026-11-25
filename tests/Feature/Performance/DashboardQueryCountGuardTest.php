<?php

use App\Enums\ActivityType;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\DealsNeedingAttentionWidget;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * One open deal per number, each with its own contact and its own owner (a member of the organization), closing on a
 * past November day.
 */
function guardedAttentionDeals(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);
        $owner = User::factory()->onboardingCompleted()->create(['name' => "Guard Owner {$i}"]);
        $organization->members()->attach($owner, ['role' => OrganizationRole::Member->value]);

        Deal::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $owner->id,
            'contact_id' => $contact->id,
            'title' => "Guard Deal {$i}",
            'value' => 1000,
            'expected_close_date' => sprintf('2026-11-%02d', $i),
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, 'Lead')),
        ]);
    }
}

/**
 * One sent invoice per number, each with its own contact, due on a past November day.
 */
function guardedOverdueInvoices(int $from, int $to): void
{
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        Invoice::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'invoice_number' => "INV-G{$i}",
            'status' => InvoiceStatus::Sent,
            'amount' => 100,
            'amount_paid' => 0,
            'paid_at' => null,
            'due_at' => sprintf('2026-11-%02d', $i),
        ]);
    }
}

/**
 * One pending task per number owned by the signed-in user, each with its own contact, due before the end of today.
 */
function guardedMyTasks(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        Task::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $me->id,
            'contact_id' => $contact->id,
            'title' => "Guard Task {$i}",
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => sprintf('2026-11-%02d 09:00:00', $i),
        ]);
    }
}

/**
 * One open deal per number owned by the signed-in user, each with its own contact, closing before the local Sunday.
 */
function guardedMyClosingDeals(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        Deal::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $me->id,
            'contact_id' => $contact->id,
            'title' => "Guard Closing Deal {$i}",
            'value' => 1000,
            'expected_close_date' => sprintf('2026-11-%02d', 10 + $i),
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, 'Lead')),
        ]);
    }
}

/**
 * One open deal per number, each closing in its own month (numbers 1 to 6 inside the six forecast months, 7 and up
 * after them), spread over four open stages.
 */
function guardedForecastDeals(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        Deal::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $me->id,
            'contact_id' => $contact->id,
            'title' => "Guard Forecast Deal {$i}",
            'value' => 1000,
            'expected_close_date' => CarbonImmutable::parse('2026-11-15')->addMonthsNoOverflow($i - 1)->toDateString(),
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, ['Lead', 'Discovery', 'Proposal Sent', 'Negotiating'][($i - 1) % 4])),
        ]);
    }
}

/**
 * One deal per number created this month, then moved through the pipeline so it has a second history row.
 */
function guardedCohortDeals(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        $deal = Deal::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $me->id,
            'contact_id' => $contact->id,
            'title' => "Guard Cohort Deal {$i}",
            'value' => 1000,
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, 'Lead')),
        ]);

        $stage = ['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won'][($i - 1) % 5];

        if ($stage !== 'Lead') {
            DealStageMover::move($deal, pipelineStageNamed($organization->id, $stage));
        }
    }
}

/**
 * One member per number with one call, one overdue task and one won deal.
 */
function guardedTeamMembers(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);
        $member = User::factory()->onboardingCompleted()->create(['name' => "Guard Member {$i}"]);
        $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

        Activity::factory()->type(ActivityType::Call)->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'user_id' => $member->id,
            'occurred_at' => '2026-11-20 10:00:00',
        ]);

        Task::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $member->id,
            'contact_id' => $contact->id,
            'title' => "Guard Team Task {$i}",
            'status' => TaskStatus::Pending,
            'due_at' => '2026-11-20 09:00:00',
        ]);

        Deal::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $me->id,
            'owner_id' => $member->id,
            'contact_id' => $contact->id,
            'title' => "Guard Won Deal {$i}",
            'value' => 1000,
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, 'Won')),
        ]);
    }
}

test('the dashboard runs the same number of queries with 2 and 9 records', function (Closure $seed, Closure $target, array $proof) {
    $seed = $seed();
    $call = $target();

    $seed(1, 2);

    $call([]);

    $small = queryCountOf(fn () => $call([]));

    $seed(3, 9);

    $large = queryCountOf(fn () => $call($proof));

    expect($small)->toBeGreaterThan(0)
        ->and($large)->toBe($small);
})->with([
    'dashboard page' => [
        fn (): Closure => fn (int $from, int $to) => guardedAttentionDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $response = test()->get(Dashboard::getUrl(tenant: test()->org))->assertSuccessful();

            foreach ($proof as $text) {
                $response->assertSee($text);
            }

            return $response;
        },
        ['Data overview'],
    ],
    'deals needing attention' => [
        fn (): Closure => fn (int $from, int $to) => guardedAttentionDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(DealsNeedingAttentionWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Deal 5', 'Guard Owner 5'],
    ],
    'overdue invoices' => [
        fn (): Closure => fn (int $from, int $to) => guardedOverdueInvoices($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(OverdueInvoicesWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['INV-G5', 'Guard Contact 5'],
    ],
    'my tasks' => [
        fn (): Closure => fn (int $from, int $to) => guardedMyTasks($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(MyTasksWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Task 5', 'Guard Contact 5'],
    ],
    'my deals closing' => [
        fn (): Closure => fn (int $from, int $to) => guardedMyClosingDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(MyDealsClosingWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Closing Deal 5', 'Guard Contact 5'],
    ],
    'deal forecast' => [
        fn (): Closure => fn (int $from, int $to) => guardedForecastDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(DealForecastWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Later', '$9,000.00'],
    ],
    'stage conversion' => [
        fn (): Closure => fn (int $from, int $to) => guardedCohortDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(StageConversionWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Deals created this month (9)'],
    ],
    'team activity' => [
        fn (): Closure => fn (int $from, int $to) => guardedTeamMembers($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(TeamActivityWidget::class);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Member 5', '100% (9 of 9)'],
    ],
]);
