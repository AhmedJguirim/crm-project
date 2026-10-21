<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->me = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->me->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->colleague = User::factory()->onboardingCompleted()->create();
    $this->actingAs($this->me);
    Filament::setTenant($this->org->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

    $this->makeTeam = fn () => $this->org->members()->attach($this->colleague, ['role' => OrganizationRole::Member->value]);

    $this->widgetDeal = fn (string $title, ?string $closeDate, ?User $owner = null, string $stage = 'Lead', array $extra = []): Deal => Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->me->id,
        'owner_id' => $owner?->id,
        'title' => $title,
        'expected_close_date' => $closeDate,
        ...DealStageMover::attributesFor(pipelineStageNamed($this->org->id, $stage)),
        ...$extra,
    ]);
});

test('it lists my late and this-week deals, oldest close date first', function () {
    ($this->makeTeam)();
    ($this->widgetDeal)('Sunday close', '2026-10-25', $this->me);
    ($this->widgetDeal)('Old late', '2026-09-30', $this->me);
    ($this->widgetDeal)('Today close', '2026-10-20', $this->me);
    ($this->widgetDeal)('Yesterday close', '2026-10-19', $this->me);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSuccessful()
        ->assertSeeInOrder(['My deals closing', 'Your open deals past their expected close date or closing this week.', 'Old late', 'Yesterday close', 'Today close', 'Sunday close']);
});

test('deals that are not listed', function (string $title, string $setup) {
    ($this->makeTeam)();
    $other = Organization::factory()->create();

    match ($setup) {
        'next monday' => ($this->widgetDeal)($title, '2026-10-26', $this->me),
        'no date' => ($this->widgetDeal)($title, null, $this->me),
        'won' => ($this->widgetDeal)($title, '2026-10-10', $this->me, 'Won'),
        'lost' => ($this->widgetDeal)($title, '2026-10-10', $this->me, 'Lost'),
        'colleague' => ($this->widgetDeal)($title, '2026-10-10', $this->colleague),
        'unassigned' => ($this->widgetDeal)($title, '2026-10-10'),
        'other org' => Deal::factory()->create([
            'organization_id' => $other->id,
            'owner_id' => $this->me->id,
            'title' => $title,
            'expected_close_date' => '2026-10-10',
            ...DealStageMover::attributesFor(pipelineStageNamed($other->id, 'Lead')),
        ]),
    };

    Livewire::test(MyDealsClosingWidget::class)->assertDontSee($title);
})->with([
    'next monday' => ['Next Monday close', 'next monday'],
    'no date' => ['No date', 'no date'],
    'won' => ['Won late', 'won'],
    'lost' => ['Lost late', 'lost'],
    'colleague' => ['Colleague late', 'colleague'],
    'unassigned' => ['Unassigned late', 'unassigned'],
    'other organization' => ['Other org late', 'other org'],
]);

test('the local week is used, not the UTC one', function () {
    ($this->makeTeam)();
    $sunday = ($this->widgetDeal)('Edge Sunday', '2026-10-25', $this->me);
    $monday = ($this->widgetDeal)('Edge Monday', '2026-10-26', $this->me);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 22:30:00', 'UTC'));

    Livewire::test(MyDealsClosingWidget::class)
        ->assertCanSeeTableRecords([$sunday])
        ->assertCanNotSeeTableRecords([$monday]);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 23:30:00', 'UTC'));

    Livewire::test(MyDealsClosingWidget::class)
        ->assertCanSeeTableRecords([$sunday, $monday]);
});

test('the close date is red when late and amber when closing today', function () {
    ($this->makeTeam)();
    $late = ($this->widgetDeal)('Late', '2026-10-19', $this->me);
    $today = ($this->widgetDeal)('Closing today', '2026-10-20', $this->me);
    $table = Livewire::test(MyDealsClosingWidget::class)->instance()->getTable();

    $column = $table->getColumn('expected_close_date')->record($late);
    expect($column->getColor($column->getState()))->toBe('danger');

    $column = $table->getColumn('expected_close_date')->record($today);
    expect($column->getColor($column->getState()))->toBe('warning');
});

test('value, contact and stage are shown', function () {
    ($this->makeTeam)();
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ana Pereira']);
    ($this->widgetDeal)('Big one', '2026-10-22', $this->me, 'Lead', ['value' => '12500.00', 'contact_id' => $contact->id]);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee(['Big one', 'Ana Pereira', '$12,500.00', 'Lead', 'Oct 22, 2026']);
});

test('the unassigned count in a team', function () {
    ($this->makeTeam)();
    ($this->widgetDeal)('Un 1', '2026-10-10');
    ($this->widgetDeal)('Un 2', '2026-10-25');
    ($this->widgetDeal)('Un 3', '2026-10-26');

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee('2 unassigned deals are past their close date or closing this week.')
        ->assertDontSee('Un 1');

    Deal::query()->where('title', 'Un 2')->first()->delete();

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee('1 unassigned deal is past its close date or closing this week.')
        ->assertDontSee('2 unassigned deals');

    Deal::query()->where('title', 'Un 1')->first()->delete();

    Livewire::test(MyDealsClosingWidget::class)->assertDontSee('unassigned');
});

test('the unassigned count ignores assigned deals, won deals and other organizations', function () {
    ($this->makeTeam)();
    ($this->widgetDeal)('Mine', '2026-10-10', $this->me);
    ($this->widgetDeal)('Theirs', '2026-10-10', $this->colleague);
    ($this->widgetDeal)('Won unassigned', '2026-10-10', null, 'Won');
    $other = Organization::factory()->create();
    Deal::factory()->create([
        'organization_id' => $other->id,
        'owner_id' => null,
        'expected_close_date' => '2026-10-10',
        ...DealStageMover::attributesFor(pipelineStageNamed($other->id, 'Lead')),
    ]);

    Livewire::test(MyDealsClosingWidget::class)->assertDontSee('unassigned');
});

test('a solo organization lists the unassigned deals and never those of other organizations', function () {
    ($this->widgetDeal)("Nobody's deal", '2026-10-10');
    $other = Organization::factory()->create();
    Deal::factory()->create([
        'organization_id' => $other->id,
        'owner_id' => null,
        'title' => 'Foreign deal',
        'expected_close_date' => '2026-10-10',
        ...DealStageMover::attributesFor(pipelineStageNamed($other->id, 'Lead')),
    ]);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee("Nobody's deal")
        ->assertSee('Your open deals past their expected close date or closing this week, including unassigned ones.')
        ->assertDontSee('Foreign deal')
        ->assertDontSee('unassigned deal');
});

test('the see all link is the mine tab in a team and the plain list when solo', function () {
    Livewire::test(MyDealsClosingWidget::class)
        ->assertActionHasUrl(TestAction::make('seeAll')->table(), DealResource::getUrl('index'));

    ($this->makeTeam)();

    Livewire::test(MyDealsClosingWidget::class)
        ->assertActionHasUrl(TestAction::make('seeAll')->table(), DealResource::getUrl('index', ['tab' => 'mine']));
});

test('the empty state', function () {
    ($this->makeTeam)();
    ($this->widgetDeal)('Next week', '2026-10-27', $this->me);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee('No deals closing this week')
        ->assertSee('You have no open deals past their expected close date or closing this week.');
});

test('every role sees its deals and the link to the deal page', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    ($this->makeTeam)();
    $this->actingAs($user);
    Filament::setTenant($this->org);
    $deal = ($this->widgetDeal)('Role deal', '2026-10-21', $user);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$deal])
        ->assertSeeHtml('href="'.DealResource::getUrl('view', ['record' => $deal]).'"');
})->with([
    'owner' => OrganizationRole::Owner,
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
    'viewer' => OrganizationRole::Viewer,
]);

test('titles, contacts and stages are escaped', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => '<b>Evil</b>']);
    ($this->widgetDeal)('<script>alert(1)</script>', '2026-10-10', $this->me, 'Lead', ['contact_id' => $contact->id]);

    Livewire::test(MyDealsClosingWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<b>Evil</b>');
});

test('five deals per page', function () {
    foreach (range(1, 6) as $i) {
        ($this->widgetDeal)("Late {$i}", "2026-10-1{$i}", $this->me);
    }

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee('Late 5')
        ->assertDontSee('Late 6')
        ->call('nextPage')
        ->assertSee('Late 6');
});

test('the dashboard renders the widget', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(MyDealsClosingWidget::class);
});

test('a forged array table search is turned into an empty search', function () {
    Livewire::test(MyDealsClosingWidget::class)
        ->set('tableSearch', ['x'])
        ->assertSuccessful()
        ->assertSet('tableSearch', '');
});
