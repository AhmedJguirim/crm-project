<?php

use App\Enums\ActivityType;
use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardV2\AverageTimeToCloseWidget;
use App\Filament\Widgets\DashboardV2\DealsClosedWidget;
use App\Filament\Widgets\DashboardV2\DealsCreatedWidget;
use App\Filament\Widgets\DashboardV2\LeadsSummaryWidget;
use App\Filament\Widgets\DashboardV2\LeadsWorkedWidget;
use App\Filament\Widgets\DashboardV2\NewContactsWidget;
use App\Filament\Widgets\DashboardV2\OpenTasksWidget;
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
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Livewire\invade;

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

/**
 * The label => value pairs of the stats the last render already computed (reads the property, runs no query).
 *
 * @return array<string, string>
 */
function statValuesOf(Testable $component): array
{
    return collect(invade($component->instance())->cachedStats ?? [])
        ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()])
        ->all();
}

/**
 * One contact per number created now; the first number also adds three contacts created in October.
 */
function guardedNewContacts(int $from, int $to): void
{
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        Contact::factory()->create(['organization_id' => $organization->id, 'name' => "Guard Contact {$i}"]);

        if ($i !== 1) {
            continue;
        }

        foreach (range(1, 3) as $n) {
            Contact::factory()->create([
                'organization_id' => $organization->id,
                'name' => "Guard October Contact {$n}",
                'created_at' => '2026-10-15 10:00:00',
            ]);
        }
    }
}

/**
 * One overdue high-priority pending task per number owned by the signed-in user, each with its own contact.
 */
function guardedOpenTasks(int $from, int $to): void
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
            'title' => "Guard Open Task {$i}",
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => '2026-11-20 09:00:00',
        ]);
    }
}

/**
 * One contact per number created now, odd numbers are leads and even numbers prospects.
 */
function guardedNewLeads(int $from, int $to): void
{
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        Contact::factory()->create([
            'organization_id' => $organization->id,
            'name' => "Guard Lead {$i}",
            'status' => $i % 2 === 1 ? ContactStatus::Lead : ContactStatus::Prospect,
        ]);
    }
}

/**
 * One lead per number, odd numbers with one call this month.
 */
function guardedWorkedLeads(int $from, int $to): void
{
    $me = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $lead = Contact::factory()->create([
            'organization_id' => $organization->id,
            'name' => "Guard Lead {$i}",
            'status' => ContactStatus::Lead,
        ]);

        if ($i % 2 !== 1) {
            continue;
        }

        Activity::factory()->type(ActivityType::Call)->create([
            'organization_id' => $organization->id,
            'contact_id' => $lead->id,
            'user_id' => $me->id,
            'occurred_at' => '2026-11-20 10:00:00',
        ]);
    }
}

/**
 * One open deal of 1000 per number created now, each with its own contact.
 */
function guardedCreatedDeals(int $from, int $to): void
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
            'title' => "Guard Created Deal {$i}",
            'value' => 1000,
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, 'Lead')),
        ]);
    }
}

/**
 * One deal of 1000 per number closed now, odd numbers won and even numbers lost.
 */
function guardedClosedDeals(int $from, int $to): void
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
            'title' => "Guard Closed Deal {$i}",
            'value' => 1000,
            ...DealStageMover::attributesFor(pipelineStageNamed($organization->id, $i % 2 === 1 ? 'Won' : 'Lost')),
        ]);
    }
}

/**
 * One deal of 1000 per number created on 15 November and won now, so exactly ten days to close.
 */
function guardedWonDeals(int $from, int $to): void
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
            'title' => "Guard Won Deal {$i}",
            'value' => 1000,
            'created_at' => '2026-11-15 12:00:00',
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
    'new contacts' => [
        fn (): Closure => fn (int $from, int $to) => guardedNewContacts($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(NewContactsWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['New Contacts Created This Month' => '9'],
    ],
    'open tasks' => [
        fn (): Closure => fn (int $from, int $to) => guardedOpenTasks($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(OpenTasksWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['Open Tasks' => '9', 'Overdue' => '9', 'Due This Week' => '0', 'High Priority' => '9'],
    ],
    'leads summary' => [
        fn (): Closure => fn (int $from, int $to) => guardedNewLeads($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(LeadsSummaryWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['New Leads This Month' => '5', 'Open Leads' => '5', 'Past Lead Status' => '4'],
    ],
    'leads worked' => [
        fn (): Closure => fn (int $from, int $to) => guardedWorkedLeads($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(LeadsWorkedWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['Leads Worked' => '5', 'Not Worked' => '4', 'Activities On Leads' => '5'],
    ],
    'deals created' => [
        fn (): Closure => fn (int $from, int $to) => guardedCreatedDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(DealsCreatedWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['Deals Created This Month' => '9', 'Value Created This Month' => '$9,000.00'],
    ],
    'deals closed' => [
        fn (): Closure => fn (int $from, int $to) => guardedClosedDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(DealsClosedWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['Deals Won This Month' => '5', 'Won Value This Month' => '$5,000.00', 'Deals Lost This Month' => '4', 'Lost Value This Month' => '$4,000.00'],
    ],
    'average time to close' => [
        fn (): Closure => fn (int $from, int $to) => guardedWonDeals($from, $to),
        fn (): Closure => function (array $proof) {
            $component = Livewire::test(AverageTimeToCloseWidget::class);

            expect(statValuesOf($component))->toMatchArray($proof);

            return $component;
        },
        ['Average Time To Close This Month' => '10 days'],
    ],
]);
