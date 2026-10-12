<?php

use App\Enums\CompanyIndustry;
use App\Enums\ContactStatus;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Support\NullsLastSort;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

it('puts empty company numbers last in both directions', function (string $column, int|string $big, int|string $small, string $direction, array $order) {
    $records = [
        'Big' => Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Big', $column => $big]),
        'Small' => Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Small', $column => $small]),
        'Empty' => Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Empty', $column => null]),
    ];

    Livewire::test(ListCompanies::class)
        ->sortTable($column, $direction)
        ->assertCanSeeTableRecords(array_map(fn (string $name): Company => $records[$name], $order), inOrder: true);
})->with([
    'employees desc' => ['employees', 2300, 1200, 'desc', ['Big', 'Small', 'Empty']],
    'employees asc' => ['employees', 2300, 1200, 'asc', ['Small', 'Big', 'Empty']],
    'revenue desc' => ['annual_revenue', 180000000, 1000, 'desc', ['Big', 'Small', 'Empty']],
    'revenue asc' => ['annual_revenue', 180000000, 1000, 'asc', ['Small', 'Big', 'Empty']],
]);

it('puts a company without industry or type last in both directions', function (string $column, string $direction) {
    $enterprise = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Enterprise']);
    $sme = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $a = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'A', 'industry' => CompanyIndustry::FinanceBanking, 'company_type_id' => $enterprise->id]);
    $b = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'B', 'industry' => CompanyIndustry::Software, 'company_type_id' => $sme->id]);
    $empty = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Empty', 'industry' => null, 'company_type_id' => null]);

    $page = Livewire::test(ListCompanies::class)->sortTable($column, $direction);
    $names = $page->instance()->getTableRecords()->pluck('name')->all();

    expect($names)->toHaveCount(3)->and(last($names))->toBe('Empty');
    $page->assertCanSeeTableRecords([$a, $b, $empty]);
})->with([
    'industry asc' => ['industry', 'asc'],
    'industry desc' => ['industry', 'desc'],
    'type asc' => ['companyTypeWithTrashed.name', 'asc'],
    'type desc' => ['companyTypeWithTrashed.name', 'desc'],
]);

it('sorts a soft-deleted company type as a value, not as empty', function () {
    $legacy = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Legacy']);
    $old = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old', 'company_type_id' => $legacy->id]);
    $empty = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Empty', 'company_type_id' => null]);
    $legacy->delete();

    Livewire::test(ListCompanies::class)
        ->sortTable('companyTypeWithTrashed.name', 'desc')
        ->assertCanSeeTableRecords([$old, $empty], inOrder: true);
});

it('keeps the company type sort inside the organization', function () {
    CompanyType::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Zeta']);
    $alpha = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Alpha']);
    $mine = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Mine', 'company_type_id' => $alpha->id]);

    Livewire::test(ListCompanies::class)
        ->sortTable('companyTypeWithTrashed.name', 'desc')
        ->assertCanSeeTableRecords([$mine]);
});

it('puts empty values last on contacts, deals, tasks and segments', function (string $page, string $column, Closure $make, string $direction) {
    [$a, $b, $empty] = $make($this->org);

    $component = Livewire::test($page)->sortTable($column, $direction);
    $ids = $component->instance()->getTableRecords()->pluck('id')->all();

    expect($ids)->toHaveCount(3)->and(last($ids))->toBe($empty->id);
    $component->assertCanSeeTableRecords([$a, $b, $empty]);
})->with(function () {
    $cases = [
        'contacts status' => [ListContacts::class, 'status', fn (Organization $org): array => [
            Contact::factory()->for($org)->create(['status' => ContactStatus::ActiveClient]),
            Contact::factory()->for($org)->create(['status' => ContactStatus::Lead]),
            Contact::factory()->for($org)->create(['status' => null]),
        ]],
        'deals value' => [ListDeals::class, 'value', fn (Organization $org): array => [
            Deal::factory()->for($org)->create(['value' => 500]),
            Deal::factory()->for($org)->create(['value' => 90000]),
            Deal::factory()->for($org)->create(['value' => null]),
        ]],
        'deals expected_close_date' => [ListDeals::class, 'expected_close_date', fn (Organization $org): array => [
            Deal::factory()->for($org)->create(['expected_close_date' => '2026-11-01']),
            Deal::factory()->for($org)->create(['expected_close_date' => '2026-12-15']),
            Deal::factory()->for($org)->create(['expected_close_date' => null]),
        ]],
        'tasks due_at' => [ListTasks::class, 'due_at', fn (Organization $org): array => [
            Task::factory()->for($org)->create(['due_at' => Carbon::parse('2026-11-01 09:00', 'UTC')]),
            Task::factory()->for($org)->create(['due_at' => Carbon::parse('2026-11-02 09:00', 'UTC')]),
            Task::factory()->for($org)->create(['due_at' => null]),
        ]],
        'segments last_synced_at' => [ListSegments::class, 'last_synced_at', fn (Organization $org): array => [
            Segment::factory()->for($org)->create(['last_synced_at' => Carbon::parse('2026-10-01 09:00', 'UTC')]),
            Segment::factory()->for($org)->create(['last_synced_at' => Carbon::parse('2026-10-02 09:00', 'UTC')]),
            Segment::factory()->for($org)->create(['last_synced_at' => null]),
        ]],
    ];

    foreach ($cases as $label => [$page, $column, $make]) {
        foreach (['asc', 'desc'] as $direction) {
            yield "$label $direction" => [$page, $column, $make, $direction];
        }
    }
});

it('never interpolates the direction argument', function () {
    $sql = NullsLastSort::column('employees')(Company::query(), 'desc; drop table companies')->toSql();

    expect($sql)->toEndWith('order by "companies"."employees" asc nulls last');
});

it('orders by the relationship subquery with its bindings', function () {
    $query = Company::query();
    $sql = NullsLastSort::relationship('companyTypeWithTrashed', 'name')($query, 'desc; --')->toSql();

    expect($sql)->toContain('nulls last')->and($sql)->not->toContain('--')->and($sql)->toContain(' asc nulls last');
});
