<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Reports\LeadSourcesWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\LeadSourcesFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(LeadSourcesFixture::now());
    $this->sources = LeadSourcesFixture::build();
});

test('the widget renders the table', function () {
    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => null])
        ->assertSee('Leads and deals by source')
        ->assertSee('Top source: Referral, 32% of the value won this month.')
        ->assertSee('Contacts created this month and deals won this month, by lead source. A won deal counts for the current lead source of its contact; a deal without a contact, or a contact without a source, counts as "Unknown source".')
        ->assertSee(['Source', 'New contacts', 'Deals won', 'Won value', 'Share'])
        ->assertSeeHtmlInOrder(['Referral', 'Upwork', 'Website', 'LinkedIn', 'Conference', 'Unknown source', 'Total'])
        ->assertSee(['$5,000.00', '$15,500.00', '32%'])
        ->assertDontSee(['$9,999.00', 'Gus Globex']);
});

test('the widget follows the page filters', function () {
    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => ['owner' => (string) $this->sources->bob->id]])
        ->assertSee('Top source: Website, 100% of the value won this month.');

    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => ['period' => 'last_month']])
        ->assertSee('Top source: Referral, 100% of the value won last month.');
});

test('there is no top source sentence when only unknown sources won', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($org->refresh());

    $this->sources->deal('U', null, DealStatus::Won, '800', $user, '2026-10-05 12:00:00', $org);

    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => null])
        ->assertSee('Unknown source')
        ->assertDontSee('Top source:');
});

test('the empty state', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization()->refresh());

    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => null])
        ->assertSee('No new contacts or won deals')
        ->assertSee('No contact was created and no deal was won this month.');
});

test('every role sees the table', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->sources->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->sources->org);

    Livewire::test(LeadSourcesWidget::class, ['pageFilters' => null])
        ->assertSee('Referral')
        ->assertSee('Total');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('forged filters reach no SQL text', function (string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(LeadSourcesWidget::class, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(SecurityPayloads::sql());

test('the widget is not on the dashboard', function () {
    expect(Livewire::test(Dashboard::class)->instance()->getWidgets())->not->toContain(LeadSourcesWidget::class);

    $this->get(Dashboard::getUrl(tenant: $this->sources->org))->assertOk()->assertDontSee('Leads and deals by source');
});
