<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Widgets\DealForecastWidget;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->org->update(['timezone' => 'UTC']);
    $this->actingAs($this->owner);
    Filament::setTenant($this->org->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));

    $this->widgetDeal = fn (string $stage, ?string $value, ?string $closeDate, DealStatus $status = DealStatus::Open, ?Organization $org = null): Deal => Deal::factory()->inStage($stage)->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->owner->id,
        'status' => $status,
        'value' => $value,
        'expected_close_date' => $closeDate,
    ]);
});

test('the widget shows formatted rows in order', function () {
    ($this->widgetDeal)('Lead', '1000', '2026-10-25');
    ($this->widgetDeal)('Negotiating', '2000', '2026-10-05');
    ($this->widgetDeal)('Proposal Sent', '4000', '2026-12-15');
    ($this->widgetDeal)('Discovery', null, '2026-11-03');
    ($this->widgetDeal)('Lead', '500', '2026-09-30');
    ($this->widgetDeal)('Negotiating', '800', '2027-04-01');
    ($this->widgetDeal)('Proposal Sent', '600', null);
    ($this->widgetDeal)('Won', '9000', '2026-10-10', DealStatus::Won);
    ($this->widgetDeal)('Lost', '7000', '2026-10-12', DealStatus::Lost);
    ($this->widgetDeal)('Lead', '5000', '2026-10-15', DealStatus::Open, Organization::factory()->create());

    Livewire::test(DealForecastWidget::class)
        ->assertSuccessful()
        ->assertSee(['Forecast by close month', 'Close month', 'Deals', 'Total', 'Weighted'])
        ->assertSeeHtmlInOrder(['Earlier', 'Oct 2026', 'Nov 2026', 'Dec 2026', 'Jan 2027', 'Feb 2027', 'Mar 2027', 'Later', 'No close date', 'Total'])
        ->assertSee(['$3,000.00', '$1,600.00', '$8,900.00', '$4,550.00', '$600.00', '$300.00', '$0.00'])
        ->assertDontSee(['$9,000.00', '$7,000.00', '$5,000.00']);
});

test('every role sees the widget', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->org);

    Livewire::test(DealForecastWidget::class)
        ->assertSuccessful()
        ->assertSee('Forecast by close month');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('the current month follows the organization timezone', function () {
    $this->org->update(['timezone' => 'Pacific/Auckland']);
    Filament::setTenant($this->org->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
    ($this->widgetDeal)('Lead', '1000', '2026-10-31');

    Livewire::test(DealForecastWidget::class)
        ->assertSeeHtmlInOrder(['Earlier', 'Nov 2026'])
        ->assertDontSee('Oct 2026')
        ->assertSee('$1,000.00');

    $this->org->update(['timezone' => 'UTC']);
    Filament::setTenant($this->org->refresh());

    Livewire::test(DealForecastWidget::class)
        ->assertSeeHtmlInOrder(['Oct 2026', 'Nov 2026'])
        ->assertDontSee('Earlier');
});

test('the dashboard renders with the widget', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(DealForecastWidget::class);
});

test('a forged array table search is turned into an empty search', function () {
    Livewire::test(DealForecastWidget::class)
        ->set('tableSearch', ['x'])
        ->assertSuccessful()
        ->assertSet('tableSearch', '');
});
