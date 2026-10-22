<?php

use App\Enums\OrganizationRole;
use App\Filament\Widgets\Reports\TimeInStageWidget;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\StageConversionFixture;
use Tests\Feature\Analytics\TimeInStageFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(StageConversionFixture::now());
    $this->team = TimeInStageFixture::build();
});

test('the widget renders the table', function () {
    Livewire::test(TimeInStageWidget::class, ['pageFilters' => null])
        ->assertSee('Time in stage')
        ->assertSee('Average days deals stayed in each stage, from the stays that ended this month, next to the open deals in each stage now. An open deal is over the average when it has been in its stage longer than that average; a deal without stage history counts in its stage but is never over it.')
        ->assertSee(['Stage', 'Average days', 'Stays', 'In stage now', 'Over average'])
        ->assertSeeHtmlInOrder(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating'])
        ->assertSee(['2.9', '3.0', '6.0'])
        ->assertDontSee('Won');
});

test('the widget follows the page filters', function () {
    Livewire::test(TimeInStageWidget::class, ['pageFilters' => ['owner' => (string) $this->team->bob->id]])
        ->assertSee(['3.3', '2.0']);

    Livewire::test(TimeInStageWidget::class, ['pageFilters' => ['period' => 'last_month']])
        ->assertSee(['9.0', 'from the stays that ended last month']);
});

test('every role sees the table', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->team->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->team->org);

    Livewire::test(TimeInStageWidget::class, ['pageFilters' => null])
        ->assertSee('Lead')
        ->assertSee('Negotiating');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a stage name typed as markup is shown as text', function (string $payload) {
    DefaultPipeline::stagesOf($this->team->org->id)->firstWhere('name', 'Lead')->update(['name' => $payload]);

    SecurityPayloads::assertHtmlIsInert(Livewire::test(TimeInStageWidget::class, ['pageFilters' => null])->assertSuccessful()->html(), $payload);
})->with(SecurityPayloads::xss());

test('forged filters reach no SQL text', function (string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(TimeInStageWidget::class, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(SecurityPayloads::sql());
