<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Reports\RevenueByClientWidget;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\RevenueByClientFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(RevenueByClientFixture::now());
    $this->money = RevenueByClientFixture::build();
});

test('the widget renders the table', function () {
    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])
        ->assertSee('Revenue by client')
        ->assertSee('Top client: Northwind, 41% of the money collected this month.')
        ->assertSee("An invoice counts for the company of its deal, else for its contact's company when the contact has exactly one, else for the contact.")
        ->assertSee(['Client', 'Collected', 'Share', 'Outstanding'])
        ->assertSeeHtmlInOrder(['Northwind', 'Umbrella (deleted)', 'Ivan Ivanov', 'Zoe Zimmer', 'Initech', 'Total'])
        ->assertSee(['$6,000.00', '$14,500.00', '$8,000.00', '41%'])
        ->assertDontSee(['$9,999.00', 'Gus Globex', 'Hooli']);
});

test('the widget follows the page filters', function () {
    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => ['owner' => (string) $this->money->bob->id]])
        ->assertSee('Top client: Umbrella (deleted), 100% of the money collected this month.')
        ->assertDontSee('Northwind');

    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => ['period' => 'last_month']])
        ->assertSee('Top client: Northwind, 100% of the money collected last month.');
});

test('there is no top client sentence when the total is not positive', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($org->refresh());

    $contact = Contact::factory()->create(['organization_id' => $org->id, 'name' => 'Nina Nobody']);
    $invoice = $this->money->invoice('N', $contact, null, $user, InvoiceStatus::Sent, '1000', '0', $org);
    $this->money->ledger($invoice, '1000', '2026-09-15');
    $this->money->ledger($invoice, '1000', '2026-10-05', refund: true);

    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])
        ->assertSee('-$1,000.00')
        ->assertDontSee('Top client:');
});

test('there is no top client sentence when refunds outweigh the best client', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($org->refresh());

    $best = $this->money->invoice('P', Contact::factory()->create(['organization_id' => $org->id, 'name' => 'Pat Plus']), null, $user, InvoiceStatus::Paid, '100', '100', $org);
    $this->money->ledger($best, '100', '2026-10-05');
    $refunded = $this->money->invoice('R', Contact::factory()->create(['organization_id' => $org->id, 'name' => 'Rex Refunded']), null, $user, InvoiceStatus::Paid, '300', '300', $org);
    $this->money->ledger($refunded, '300', '2026-09-05');
    $this->money->ledger($refunded, '300', '2026-10-06', refund: true);

    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])
        ->assertSee(['Pat Plus', 'Rex Refunded', '-$200.00'])
        ->assertDontSee('Top client:');
});

test('the empty state', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization()->refresh());

    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])
        ->assertSee('No revenue')
        ->assertSee('No money was collected this month and no invoice is unpaid.');
});

test('every role sees the table', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->money->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->money->org);

    Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])
        ->assertSee('Northwind')
        ->assertSee('Total');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a company name typed as markup is shown as text', function (string $payload) {
    $this->money->companies['Northwind']->update(['name' => $payload]);

    $html = Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])->assertSuccessful()->html();

    SecurityPayloads::assertHtmlIsInert($html, $payload);
})->with(SecurityPayloads::xss());

test('a contact name typed as markup is shown as text', function (string $payload) {
    $this->money->contacts['Zoe Zimmer']->update(['name' => $payload]);

    SecurityPayloads::assertHtmlIsInert(Livewire::test(RevenueByClientWidget::class, ['pageFilters' => null])->assertSuccessful()->html(), $payload);
})->with(SecurityPayloads::xss());

test('forged filters reach no SQL text', function (string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(RevenueByClientWidget::class, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(SecurityPayloads::sql());

test('the widget is not on the dashboard', function () {
    expect(Livewire::test(Dashboard::class)->instance()->getWidgets())->not->toContain(RevenueByClientWidget::class);

    $this->get(Dashboard::getUrl(tenant: $this->money->org))->assertOk()->assertDontSee('Revenue by client');
});
