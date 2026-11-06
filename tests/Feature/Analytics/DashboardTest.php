<?php

use App\Enums\ContactStatus;
use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Widgets\DashboardV2\ActivityTypesWidget;
use App\Filament\Widgets\DashboardV2\AverageTimeToCloseWidget;
use App\Filament\Widgets\DashboardV2\ContactSourcesWidget;
use App\Filament\Widgets\DashboardV2\ContactsOverTimeWidget;
use App\Filament\Widgets\DashboardV2\DealRevenueBySourceWidget;
use App\Filament\Widgets\DashboardV2\DealsByStageWidget;
use App\Filament\Widgets\DashboardV2\DealsByStatusWidget;
use App\Filament\Widgets\DashboardV2\DealsClosedWidget;
use App\Filament\Widgets\DashboardV2\DealsCreatedWidget;
use App\Filament\Widgets\DashboardV2\DealsOverTimeWidget;
use App\Filament\Widgets\DashboardV2\LeadsBySourceWidget;
use App\Filament\Widgets\DashboardV2\LeadsFunnelWidget;
use App\Filament\Widgets\DashboardV2\LeadsSummaryWidget;
use App\Filament\Widgets\DashboardV2\LeadsWorkedWidget;
use App\Filament\Widgets\DashboardV2\NewContactsWidget;
use App\Filament\Widgets\DashboardV2\OpenTasksWidget;
use App\Filament\Widgets\DashboardV2\OwnerPerformanceWidget;
use App\Filament\Widgets\DashboardV2\TaskStatusWidget;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\DealsNeedingAttentionWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\Reports\LostReasonsWidget;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Filament\Widgets\RevenueByMonthWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Reports\DashboardBreakdowns;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Analytics\TeamActivityFixture;
use Tests\Feature\Security\SecurityPayloads;

use function Livewire\invade;

beforeEach(function () {
    $this->travelTo(TeamActivityFixture::now());
    $this->team = TeamActivityFixture::build();
    $this->org = $this->team->org;

    $this->data = fn (string $widget, array $pageFilters = []): array => invade(Livewire::test($widget, ['pageFilters' => $pageFilters])->instance())->getData();
    $this->stats = fn (string $widget, array $pageFilters = []): array => collect(invade(Livewire::test($widget, ['pageFilters' => $pageFilters])->instance())->getStats())
        ->mapWithKeys(fn ($stat): array => [$stat->getLabel() => (string) $stat->getValue()])
        ->all();
});

afterEach(function () {
    Filament::setTenant(null);
});

test('every role opens the dashboard with its four dashboards', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->org);

    $this->get(Dashboard::getUrl(tenant: $this->org))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('Dashboard v2')
        ->assertSee('Data overview')
        ->assertSee('Leads')
        ->assertSee('Pipeline')
        ->assertSee('Finance')
        ->assertSeeLivewire(Dashboard::class)
        ->assertSeeLivewire(NewContactsWidget::class)
        ->assertSeeLivewire(ContactSourcesWidget::class)
        ->assertSeeLivewire(ContactsOverTimeWidget::class)
        ->assertSeeLivewire(DealsOverTimeWidget::class)
        ->assertSeeLivewire(DealsByStageWidget::class)
        ->assertSeeLivewire(ActivityTypesWidget::class)
        ->assertSeeLivewire(TeamActivityWidget::class)
        ->assertSeeLivewire(OpenTasksWidget::class)
        ->assertSeeLivewire(TaskStatusWidget::class)
        ->assertSeeLivewire(LeadsSummaryWidget::class)
        ->assertSeeLivewire(LeadsBySourceWidget::class)
        ->assertSeeLivewire(LeadsFunnelWidget::class)
        ->assertSeeLivewire(LostReasonsWidget::class)
        ->assertSeeLivewire(LeadsWorkedWidget::class)
        ->assertSeeLivewire(DealRevenueBySourceWidget::class)
        ->assertSeeLivewire(DealsCreatedWidget::class)
        ->assertSeeLivewire(DealsClosedWidget::class)
        ->assertSeeLivewire(DealsByStatusWidget::class)
        ->assertSeeLivewire(OwnerPerformanceWidget::class)
        ->assertSeeLivewire(StageConversionWidget::class)
        ->assertSeeLivewire(AverageTimeToCloseWidget::class)
        ->assertSeeLivewire(DealsNeedingAttentionWidget::class)
        ->assertSeeLivewire(KpiOverviewWidget::class)
        ->assertSeeLivewire(MoneyOwedWidget::class)
        ->assertSeeLivewire(RevenueByMonthWidget::class)
        ->assertSeeLivewire(OverdueInvoicesWidget::class)
        ->assertSeeLivewire(DealForecastWidget::class)
        ->assertSeeLivewire(MyTasksWidget::class)
        ->assertSeeLivewire(MyDealsClosingWidget::class);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a user of another organization cannot open the dashboard of this one', function () {
    $this->actingAs($this->team->carol);
    $expected = $this->get(Filament::getUrl($this->org))->status();

    expect($expected)->toBeIn([403, 404]);

    $this->get(Dashboard::getUrl(tenant: $this->org))->assertStatus($expected);
});

test('the dashboard is the panel home and the only one', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class);

    expect(Dashboard::getUrl(tenant: $this->org))->toBe(Filament::getUrl($this->org))
        ->and(Route::has('filament.admin.pages.dashboard-v2'))->toBeFalse()
        ->and(collect(Filament::getPages())->filter(fn (string $page): bool => str_contains(class_basename($page), 'Dashboard'))->values()->all())->toBe([Dashboard::class])
        ->and(collect(Filament::getNavigation())->flatMap(fn (NavigationGroup $group) => $group->getItems())->filter(fn (NavigationItem $item): bool => $item->getLabel() === 'Dashboard'))->toHaveCount(1);
});

test('the dashboard lists the widgets of its four dashboards and the panel registers no other one', function () {
    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)->toHaveCount(count(array_unique($widgets)))
        ->and($widgets)->toContain(NewContactsWidget::class, LeadsSummaryWidget::class, DealsClosedWidget::class, KpiOverviewWidget::class, MyTasksWidget::class)
        ->and($widgets)->not->toContain(AccountWidget::class, FilamentInfoWidget::class)
        ->and(Filament::getWidgets())->not->toContain(NewContactsWidget::class, DealsByStageWidget::class, LeadsSummaryWidget::class);

    foreach (['DealsOverviewWidget', 'ContactsOverviewWidget', 'RecentActivitiesWidget'] as $deleted) {
        expect(file_exists(app_path('Filament/Widgets/'.$deleted.'.php')))->toBeFalse()
            ->and(Filament::getWidgets())->not->toContain('App\\Filament\\Widgets\\'.$deleted)
            ->and($widgets)->not->toContain('App\\Filament\\Widgets\\'.$deleted);
    }
});

test('the page has the shared filter form', function () {
    Livewire::test(Dashboard::class)
        ->assertFormFieldExists('period', 'filtersForm')
        ->assertFormFieldExists('owner', 'filtersForm')
        ->assertFormFieldHidden('from', 'filtersForm')
        ->fillForm(['period' => ReportPeriodPreset::Custom], 'filtersForm')
        ->assertFormFieldVisible('from', 'filtersForm')
        ->assertFormFieldVisible('to', 'filtersForm');
});

test('the dashboard hands its filters to the widgets of every tab', function () {
    $page = Livewire::test(Dashboard::class)->set('filters.owner', 'me')->set('filters.period', 'last_month');

    foreach ([NewContactsWidget::class, LeadsSummaryWidget::class, KpiOverviewWidget::class, DealsNeedingAttentionWidget::class] as $widget) {
        $component = $page->instance()->getWidgetsSchemaComponents([$widget])[0];

        expect($component->getData()['pageFilters'])->toMatchArray(['owner' => 'me', 'period' => 'last_month']);
    }
});

test('the deals closed stats open the deal list filtered on the status', function () {
    $stats = collect(invade(Livewire::test(DealsClosedWidget::class)->instance())->getStats())
        ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => $stat->getUrl()]);
    $won = DealResource::getUrl('index').'?filters[status][value]='.DealStatus::Won->value;
    $lost = DealResource::getUrl('index').'?filters[status][value]='.DealStatus::Lost->value;

    expect($stats->filter(fn (?string $url, string $label): bool => str_starts_with($label, 'Deals Won') || str_starts_with($label, 'Won Value'))->values()->all())->toBe([$won, $won])
        ->and($stats->filter(fn (?string $url, string $label): bool => str_starts_with($label, 'Deals Lost') || str_starts_with($label, 'Lost Value'))->values()->all())->toBe([$lost, $lost]);

    foreach ([DealStatus::Won, DealStatus::Lost] as $status) {
        Livewire::withQueryParams(['filters' => ['status' => ['value' => $status->value]]])
            ->test(ListDeals::class)
            ->assertSet('tableFilters.status.value', $status->value);
    }
});

test('the lead stats open the contact list filtered on the lead status', function () {
    $stats = collect(invade(Livewire::test(LeadsSummaryWidget::class)->instance())->getStats())
        ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => $stat->getUrl()]);
    $leads = ContactResource::getUrl('index').'?filters[status][values][0]='.ContactStatus::Lead->value;

    expect($stats->filter(fn (?string $url, string $label): bool => str_starts_with($label, 'New Leads') || $label === 'Open Leads')->values()->all())->toBe([$leads, $leads]);

    Livewire::withQueryParams(['filters' => ['status' => ['values' => [ContactStatus::Lead->value]]]])
        ->test(ListContacts::class)
        ->assertSet('tableFilters.status.values', [ContactStatus::Lead->value]);
});

test('forged filters reach no SQL text', function (string $widget, string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(function (): Generator {
    foreach ((new Dashboard)->getWidgets() as $widget) {
        foreach (SecurityPayloads::sql() as $name => [$payload]) {
            yield class_basename($widget).' '.$name => [$widget, $payload];
        }
    }
});

test('every dashboard widget renders with far dates', function (string $widget, string $from, string $to) {
    Livewire::test($widget, ['pageFilters' => ['period' => 'custom', 'from' => $from, 'to' => $to]])->assertSuccessful();
})->with(function (): Generator {
    foreach ((new Dashboard)->getWidgets() as $widget) {
        foreach (['0001-01-01 to 9999-12-31' => ['0001-01-01', '9999-12-31'], 'the widest allowed range' => ['1900-01-01', '2100-12-31']] as $name => [$from, $to]) {
            yield class_basename($widget).' '.$name => [$widget, $from, $to];
        }
    }
});

describe('breakdown service', function () {
    test('the widest range is drawn per month with a bounded number of points', function () {
        Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-06-20 10:00:00']);

        $series = DashboardBreakdowns::createdSeries(Contact::query(), ReportPeriod::custom('1900-01-01', '2100-12-31', 'Europe/Paris'));

        expect($series['unit'])->toBe('month')
            ->and($series['points'])->toHaveCount(2412)
            ->and($series['points']['2026-06-01'])->toBe(1);
    });

    test('created series fills every local day and buckets by the local timezone', function () {
        // 2026-06-20 22:30 UTC is already 2026-06-21 in Paris (UTC+2).
        Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-06-20 22:30:00']);
        Contact::factory()->count(2)->create(['organization_id' => $this->org->id, 'created_at' => '2026-06-19 10:00:00']);

        $period = ReportPeriod::custom('2026-06-19', '2026-06-22', 'Europe/Paris');
        $series = DashboardBreakdowns::createdSeries(Contact::query(), $period);

        expect($series)->toBe(['unit' => 'day', 'points' => ['2026-06-19' => 2, '2026-06-20' => 0, '2026-06-21' => 1, '2026-06-22' => 0]]);
    });

    test('a period of more than three months is drawn per month', function () {
        Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-02-10 10:00:00']);
        Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-02-20 10:00:00']);

        $series = DashboardBreakdowns::createdSeries(Contact::query(), ReportPeriod::custom('2026-01-15', '2026-06-30', 'Europe/Paris'));

        expect($series['unit'])->toBe('month')
            ->and(array_keys($series['points']))->toBe(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'])
            ->and($series['points']['2026-02-01'])->toBe(2);
    });

    test('records of another organization are never counted', function () {
        $before = DashboardBreakdowns::countsBy(Contact::query(), 'status');

        Contact::factory()->create(['organization_id' => $this->team->globex->id, 'status' => ContactStatus::Partner]);

        expect(DashboardBreakdowns::countsBy(Contact::query(), 'status'))->toBe($before);
    });
});

describe('widgets', function () {
    beforeEach(function () {
        $this->contact = fn (array $attributes = []): Contact => Contact::factory()->create($attributes + ['organization_id' => $this->org->id, 'owner_id' => $this->team->alice->id]);
    });

    test('the contact sources count the contacts created in the period by lead source', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        ($this->contact)(['lead_source' => LeadSource::Referral, 'created_at' => '2026-06-10 10:00:00']);
        ($this->contact)(['lead_source' => LeadSource::Referral, 'created_at' => '2026-06-11 10:00:00']);
        ($this->contact)(['lead_source' => LeadSource::Website, 'created_at' => '2026-06-12 10:00:00']);
        ($this->contact)(['lead_source' => null, 'created_at' => '2026-06-12 10:00:00']);
        ($this->contact)(['lead_source' => LeadSource::Website, 'created_at' => '2026-05-12 10:00:00']);

        $data = ($this->data)(ContactSourcesWidget::class, $filters);

        expect(array_combine($data['labels'], $data['datasets'][0]['data']))->toBe(['Referral' => 2, 'Website' => 1, 'Unknown source' => 1]);
    });

    test('a chart without data returns nothing so the empty state shows', function () {
        expect(($this->data)(ContactSourcesWidget::class, ['period' => ReportPeriodPreset::Custom->value, 'from' => '2020-01-01', 'to' => '2020-01-31']))->toBe([]);
    });

    test('the contacts over time chart has a point per local day', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-05'];
        ($this->contact)(['created_at' => '2026-06-02 10:00:00']);
        ($this->contact)(['created_at' => '2026-06-02 11:00:00', 'owner_id' => $this->team->bob->id]);

        $everyone = ($this->data)(ContactsOverTimeWidget::class, $filters);
        $alice = ($this->data)(ContactsOverTimeWidget::class, $filters + ['owner' => (string) $this->team->alice->id]);

        expect($everyone['labels'])->toBe(['Jun 1', 'Jun 2', 'Jun 3', 'Jun 4', 'Jun 5'])
            ->and($everyone['datasets'][0]['data'])->toBe([0, 2, 0, 0, 0])
            ->and($alice['datasets'][0]['data'])->toBe([0, 1, 0, 0, 0]);
    });

    test('the deals by stage chart counts the open deals only, in pipeline order', function () {
        $deal = fn (string $stage, DealStatus $status = DealStatus::Open): Deal => Deal::factory()->inStage($stage)->create(['organization_id' => $this->org->id, 'owner_id' => $this->team->alice->id, 'status' => $status]);
        $before = ($this->data)(DealsByStageWidget::class);
        $beforeCounts = $before === [] ? [] : array_combine($before['labels'], $before['datasets'][0]['data']);

        $deal('Lead');
        $deal('Lead');
        $deal('Lost', DealStatus::Lost);

        $after = ($this->data)(DealsByStageWidget::class);
        $afterCounts = array_combine($after['labels'], $after['datasets'][0]['data']);

        expect($afterCounts['Lead'])->toBe(($beforeCounts['Lead'] ?? 0) + 2)
            ->and($afterCounts['Lost'] ?? 0)->toBe($beforeCounts['Lost'] ?? 0);
    });

    test('the leads summary counts the leads and the contacts past the lead status, not the ones without a status', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        ($this->contact)(['status' => ContactStatus::Lead, 'created_at' => '2026-06-10 10:00:00']);
        ($this->contact)(['status' => ContactStatus::Lead, 'created_at' => '2026-06-11 10:00:00']);
        ($this->contact)(['status' => ContactStatus::Prospect, 'created_at' => '2026-06-12 10:00:00']);
        ($this->contact)(['status' => null, 'created_at' => '2026-06-13 10:00:00']);
        ($this->contact)(['status' => ContactStatus::Lead, 'created_at' => '2026-03-12 10:00:00']);

        $stats = ($this->stats)(LeadsSummaryWidget::class, $filters);

        expect($stats['New Leads In Period'])->toBe('2')
            ->and($stats['Past Lead Status'])->toBe('1')
            ->and((int) $stats['Open Leads'])->toBeGreaterThanOrEqual(3);
    });

    test('the leads funnel counts the contacts without a status in their own bar', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        ($this->contact)(['status' => ContactStatus::Lead, 'created_at' => '2026-06-10 10:00:00']);
        ($this->contact)(['status' => null, 'created_at' => '2026-06-11 10:00:00']);
        ($this->contact)(['status' => null, 'created_at' => '2026-06-12 10:00:00']);

        $data = ($this->data)(LeadsFunnelWidget::class, $filters);

        expect(array_combine($data['labels'], $data['datasets'][0]['data']))->toBe(['Lead' => 1, 'No status' => 2]);
    });

    test('the leads worked summary tells the worked leads from the others', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        $open = ($this->stats)(LeadsWorkedWidget::class, $filters);

        $worked = ($this->contact)(['status' => ContactStatus::Lead]);
        ($this->contact)(['status' => ContactStatus::Lead]);
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $worked->id, 'user_id' => $this->team->alice->id, 'occurred_at' => '2026-06-10 10:00:00']);
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $worked->id, 'user_id' => $this->team->alice->id, 'occurred_at' => '2026-05-10 10:00:00']);

        $stats = ($this->stats)(LeadsWorkedWidget::class, $filters);

        expect((int) $stats['Leads Worked'])->toBe((int) $open['Leads Worked'] + 1)
            ->and((int) $stats['Not Worked'])->toBe((int) $open['Not Worked'] + 1)
            ->and((int) $stats['Activities On Leads'])->toBe((int) $open['Activities On Leads'] + 1);
    });

    test('the task widgets count the open, overdue and completed tasks', function () {
        $task = fn (array $attributes): Task => Task::factory()->create($attributes + ['organization_id' => $this->org->id, 'owner_id' => $this->team->alice->id]);
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-10-01', 'to' => '2026-10-31'];
        $before = ($this->stats)(OpenTasksWidget::class, $filters);

        $task(['status' => TaskStatus::Pending, 'due_at' => '2026-10-01 10:00:00']);
        $task(['status' => TaskStatus::Pending, 'due_at' => '2027-03-01 10:00:00']);
        $task(['status' => TaskStatus::Done, 'completed_at' => '2026-10-10 10:00:00', 'due_at' => '2026-10-09 10:00:00']);

        $after = ($this->stats)(OpenTasksWidget::class, $filters);
        $chart = ($this->data)(TaskStatusWidget::class, $filters);

        expect((int) $after['Open Tasks'])->toBe((int) $before['Open Tasks'] + 2)
            ->and((int) $after['Overdue'])->toBe((int) $before['Overdue'] + 1)
            ->and($chart['labels'])->toContain('Open, on time', 'Overdue', 'Completed');
    });

    test('a forged owner and a forged period fall back to everyone this month', function () {
        $carol = $this->team->carol;
        ($this->contact)(['created_at' => '2026-10-15 10:00:00']);

        $expected = ($this->data)(ContactsOverTimeWidget::class);

        expect(($this->data)(ContactsOverTimeWidget::class, ['owner' => (string) $carol->id, 'period' => '1 OR 1=1']))->toBe($expected)
            ->and(($this->data)(ContactsOverTimeWidget::class, ['owner' => '1 OR 1=1']))->toBe($expected);
    });

    test('the average time to close is in days and the stats render without deals', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2020-01-01', 'to' => '2020-01-31'];

        expect(($this->stats)(AverageTimeToCloseWidget::class, $filters))->toBe(['Average Time To Close In Period' => '—'])
            ->and(array_values(($this->stats)(DealsCreatedWidget::class, $filters))[0])->toBe('0')
            ->and(array_values(($this->stats)(DealsClosedWidget::class, $filters))[0])->toBe('0')
            ->and(($this->data)(DealsByStatusWidget::class, $filters))->toBe([])
            ->and(($this->data)(OwnerPerformanceWidget::class, $filters))->toBe([])
            ->and(($this->data)(DealRevenueBySourceWidget::class, $filters))->toBe([])
            ->and(($this->data)(ActivityTypesWidget::class, $filters))->toBe([]);
    });

    test('the average time to close averages the days from creation to win', function () {
        $filters = ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        $won = fn (string $createdAt, string $wonAt): Deal => Deal::factory()->inStage('Won')->create([
            'organization_id' => $this->org->id, 'owner_id' => $this->team->alice->id, 'status' => DealStatus::Won,
            'created_at' => $createdAt, 'won_at' => $wonAt, 'value' => 100,
        ]);
        $won('2026-06-01 10:00:00', '2026-06-05 10:00:00');
        $won('2026-06-02 10:00:00', '2026-06-08 10:00:00');

        expect(($this->stats)(AverageTimeToCloseWidget::class, $filters))->toBe(['Average Time To Close In Period' => '5 days']);
    });
});
