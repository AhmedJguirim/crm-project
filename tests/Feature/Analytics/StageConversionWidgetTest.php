<?php

use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\StageConversionFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(StageConversionFixture::now());
    $this->team = StageConversionFixture::build();
});

test('the widget renders the table', function () {
    Livewire::test(StageConversionWidget::class, ['pageFilters' => null])
        ->assertSee('Stage conversion')
        ->assertSee('Deals created this month (6) and the furthest stage each one reached. A skipped stage counts as reached, lost deals count up to the stage they were lost from, and the last row counts the deals won now.')
        ->assertSee(['Stage', 'Deals', 'Of new deals', 'From previous stage'])
        ->assertSeeHtmlInOrder(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won'])
        ->assertSee(['83%', '80%', '17%']);
});

test('the widget follows the page filters', function () {
    Livewire::test(StageConversionWidget::class, ['pageFilters' => ['owner' => (string) $this->team->bob->id]])
        ->assertSee('Deals created this month (2)');

    Livewire::test(StageConversionWidget::class, ['pageFilters' => ['period' => 'last_month']])
        ->assertSee('Deals created last month (1)');
});

test('an empty cohort shows the empty state', function () {
    $phrase = ReportPeriod::custom('2026-06-01', '2026-06-30', 'Europe/Paris')->phrase();

    Livewire::test(StageConversionWidget::class, ['pageFilters' => ['period' => ReportPeriodPreset::Custom->value, 'from' => '2026-06-01', 'to' => '2026-06-30']])
        ->assertSee('No new deals')
        ->assertSee("No deals were created {$phrase}.")
        ->assertDontSee('Negotiating');
});

test('every role sees the table', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->team->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->team->org);

    Livewire::test(StageConversionWidget::class, ['pageFilters' => null])
        ->assertSee('Lead')
        ->assertSee('Won');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a stage name typed as markup is shown as text', function (string $payload) {
    DefaultPipeline::stagesOf($this->team->org->id)->firstWhere('name', 'Lead')->update(['name' => $payload]);

    SecurityPayloads::assertHtmlIsInert(Livewire::test(StageConversionWidget::class, ['pageFilters' => null])->assertSuccessful()->html(), $payload);
})->with(SecurityPayloads::xss());

test('forged filters reach no SQL text', function (string $payload) {
    $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

    $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(StageConversionWidget::class, ['pageFilters' => $filters])->assertSuccessful());

    SecurityPayloads::assertSqlIsClean($sql, $payload);
    expect($sql)->not->toBeEmpty();
})->with(SecurityPayloads::sql());
