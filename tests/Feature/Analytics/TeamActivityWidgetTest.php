<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\TeamActivityFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(TeamActivityFixture::now());
    $this->team = TeamActivityFixture::build();
});

test('the widget renders the table', function () {
    Livewire::test(TeamActivityWidget::class, ['pageFilters' => null])
        ->assertSee('Team activity')
        ->assertSee('Per member, this month. Overdue tasks are counted as of now; unassigned records are not counted.')
        ->assertSeeHtmlInOrder(['Bob Brown', 'Alice Anders', 'Fred Former (no longer a member)', 'Total'])
        ->assertSee(['$2,000.00', '$1,500.00', '$300.00', '$3,800.00', '67% (2 of 3)', '80% (4 of 5)', 'Call 2 · Email 1 · Meeting 1'])
        ->assertDontSee(['$9,000.00', '$5,000.00', 'Carol Clark', 'Vera Viewer'])
        ->assertSee(['Member', 'Activities', 'Tasks done', 'Overdue tasks', 'New deals', 'Deals won', 'Won value', 'Win rate']);
});

test('the widget follows the page filters', function () {
    Livewire::test(TeamActivityWidget::class, ['pageFilters' => ['period' => 'last_month']])
        ->assertSee('Per member, last month. Overdue tasks are counted as of now; unassigned records are not counted.');

    Livewire::test(TeamActivityWidget::class, ['pageFilters' => ['owner' => (string) $this->team->bob->id]])
        ->assertSee('Bob Brown')
        ->assertDontSee('Alice Anders');
});

test('every role sees every member row', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->team->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->team->org);

    Livewire::test(TeamActivityWidget::class, ['pageFilters' => null])
        ->assertSee('Bob Brown')
        ->assertSee('Alice Anders');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a member name typed as markup is shown as text', function (string $payload) {
    $this->team->bob->update(['name' => $payload]);

    SecurityPayloads::assertHtmlIsInert(Livewire::test(TeamActivityWidget::class, ['pageFilters' => null])->assertSuccessful()->html(), $payload);
})->with(SecurityPayloads::xss());

test('forged filters reach no SQL text', function (string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(TeamActivityWidget::class, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(SecurityPayloads::sql());

test('the widget is not on the dashboard', function () {
    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)->not->toContain(TeamActivityWidget::class);

    $this->get(Dashboard::getUrl(tenant: $this->team->org))->assertOk()->assertDontSee('Team activity');
});
