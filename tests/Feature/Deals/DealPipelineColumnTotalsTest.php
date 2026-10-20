<?php

use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Eur]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->stage = fn (string $stage) => pipelineStageNamed($this->org->id, $stage);
    $this->makeDeal = fn (string $title, string $stage, DealStatus $status, ?string $value, $org = null) => Deal::factory()->inStage($stage)->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'status' => $status,
        'value' => $value,
    ]);
    $this->summary = fn (string $stage, $component = null): ?array => ($component ?? Livewire::test(DealPipeline::class))->instance()->getBoardColumnSummary((string) ($this->stage)($stage)->id);

    $this->migration = ($this->makeDeal)('Migration', 'Discovery', DealStatus::Open, '12300');
    ($this->makeDeal)('Retainer', 'Lead', DealStatus::Open, '5000');
    ($this->makeDeal)('Audit', 'Lead', DealStatus::Open, '1234.50');
    ($this->makeDeal)('Support plan', 'Negotiating', DealStatus::Open, null);
    ($this->makeDeal)('Licence', 'Won', DealStatus::Won, '8000');
    ($this->makeDeal)('Hardware', 'Lost', DealStatus::Lost, '2000');

    $this->otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    ($this->makeDeal)('Foreign', 'Lead', DealStatus::Open, '99999', $this->otherOrg);
});

test('every column shows its total, open stages also the weighted total', function () {
    $page = Livewire::test(DealPipeline::class);

    expect(($this->summary)('Lead', $page))->toBe(['text' => 'Total €6,234.50 · Weighted €623.45', 'hint' => 'Weighted: the total × 10% win probability'])
        ->and(($this->summary)('Discovery', $page))->toBe(['text' => 'Total €12,300.00 · Weighted €3,075.00', 'hint' => 'Weighted: the total × 25% win probability'])
        ->and(($this->summary)('Proposal Sent', $page))->toBe(['text' => 'Total €0.00 · Weighted €0.00', 'hint' => 'Weighted: the total × 50% win probability'])
        ->and(($this->summary)('Negotiating', $page))->toBe(['text' => 'Total €0.00 · Weighted €0.00', 'hint' => 'Weighted: the total × 75% win probability'])
        ->and(($this->summary)('Won', $page))->toBe(['text' => 'Total €8,000.00', 'hint' => null])
        ->and(($this->summary)('Lost', $page))->toBe(['text' => 'Total €2,000.00', 'hint' => null]);

    $page->assertSee('Total €12,300.00 · Weighted €3,075.00')
        ->assertSeeHtml('title="Weighted: the total × 25% win probability"')
        ->assertDontSee('Weighted €8,000.00')
        ->assertDontSee('99,999');
});

test('the totals follow the search', function () {
    $page = Livewire::test(DealPipeline::class)->set('tableSearch', 'Ret');

    expect(($this->summary)('Lead', $page))->toBe(['text' => 'Total €5,000.00 · Weighted €500.00', 'hint' => 'Weighted: the total × 10% win probability'])
        ->and(($this->summary)('Discovery', $page)['text'])->toBe('Total €0.00 · Weighted €0.00');
});

test('the totals follow the status filter', function () {
    $page = Livewire::test(DealPipeline::class)->set('tableFilters.status.value', DealStatus::Won->value);

    expect(($this->summary)('Lead', $page)['text'])->toBe('Total €0.00 · Weighted €0.00')
        ->and(($this->summary)('Won', $page)['text'])->toBe('Total €8,000.00');
});

test('a changed stage probability changes the weighted total', function () {
    ($this->stage)('Discovery')->update(['probability' => 40]);

    expect(($this->summary)('Discovery'))->toBe(['text' => 'Total €12,300.00 · Weighted €4,920.00', 'hint' => 'Weighted: the total × 40% win probability']);
});

test('a drag moves the value to the new column', function () {
    $page = Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $this->migration->id, (string) ($this->stage)('Negotiating')->id);

    expect(($this->summary)('Negotiating', $page)['text'])->toBe('Total €12,300.00 · Weighted €9,225.00')
        ->and(($this->summary)('Discovery', $page)['text'])->toBe('Total €0.00 · Weighted €0.00');
});

test('every role sees the totals', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->org);

    Livewire::test(DealPipeline::class)->assertSee('Total €6,234.50 · Weighted €623.45');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('one aggregate query per render', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::test(DealPipeline::class);

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'SUM(value)')))->toHaveCount(1);
});

test('an unknown or forged column id has no summary', function () {
    $foreignLead = PipelineStage::query()->withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->where('name', 'Lead')->firstOrFail();
    $page = Livewire::test(DealPipeline::class)->instance();

    expect($page->getBoardColumnSummary('999999'))->toBeNull()
        ->and($page->getBoardColumnSummary('abc'))->toBeNull()
        ->and($page->getBoardColumnSummary((string) $foreignLead->id))->toBeNull();
});

test('the weighted value of a stage follows its probability', function () {
    expect((new PipelineStage(['probability' => 25]))->weightedValue(12300.0))->toBe(3075.0)
        ->and((new PipelineStage(['probability' => 0]))->weightedValue(12300.0))->toBe(0.0)
        ->and((new PipelineStage(['probability' => 100]))->weightedValue(12300.0))->toBe(12300.0);
});
