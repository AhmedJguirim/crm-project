<?php

use App\Enums\DealStage;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DealOwnerOptions;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Martin']);
    $this->org = $this->alice->personalOrganization();
    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Stone']);
    $this->vera = User::factory()->onboardingCompleted()->create(['name' => 'Vera Viewer']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->vera, ['role' => OrganizationRole::Viewer->value]);

    $this->eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Eve Other']);

    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->makeDeal = fn (string $title, ?User $owner, ?Organization $org = null): Deal => Deal::factory()->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->alice->id,
        'owner_id' => $owner?->id,
        'title' => $title,
        'contact_id' => null,
    ]);

    $this->formerMember = function (string $dealTitle = 'Legacy'): array {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        $deal = ($this->makeDeal)($dealTitle, $carl);
        $this->org->members()->detach($carl->id);

        return [$carl, $deal->fresh()];
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a new deal is owned by its creator by default', function () {
    Livewire::test(CreateDeal::class)
        ->assertSchemaStateSet(['owner_id' => $this->alice->id])
        ->fillForm(['title' => 'Retainer', 'stage' => DealStage::Lead])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Deal::query()->where('title', 'Retainer')->firstOrFail()->owner_id)->toBe($this->alice->id);
});

test('the owner options are the current members ordered by name', function () {
    expect(DealOwnerOptions::forForm(null))->toBe([
        $this->alice->id => 'Alice Martin',
        $this->bob->id => 'Bob Stone',
        $this->vera->id => 'Vera Viewer',
    ])->and(DealOwnerOptions::forFilter())->toBe(DealOwnerOptions::forForm(null));
});

test('a deal can be given to another member or to nobody', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => "Bob's deal", 'stage' => DealStage::Lead, 'owner_id' => $this->bob->id])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => "Nobody's deal", 'stage' => DealStage::Lead, 'owner_id' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Deal::query()->where('title', "Bob's deal")->firstOrFail()->owner_id)->toBe($this->bob->id)
        ->and(Deal::query()->where('title', "Nobody's deal")->firstOrFail()->owner_id)->toBeNull();

    Livewire::test(ListDeals::class)
        ->assertTableColumnStateSet('owner_id', null, Deal::query()->where('title', "Nobody's deal")->firstOrFail())
        ->assertSee('No owner')
        ->assertTableColumnStateSet('owner_id', 'Bob Stone', Deal::query()->where('title', "Bob's deal")->firstOrFail());
});

test('a forged owner outside the options is refused', function (string $forged) {
    $value = match ($forged) {
        'other organization' => $this->eve->id,
        'letters' => 'abc',
        'unknown id' => 99999999,
    };

    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Forged', 'stage' => DealStage::Lead, 'owner_id' => $value])
        ->call('create')
        ->assertHasFormErrors(['owner_id' => 'in']);

    expect(Deal::query()->where('title', 'Forged')->exists())->toBeFalse();
})->with(['other organization', 'letters', 'unknown id']);

test('a removed member stays the owner and is marked everywhere', function () {
    [$carl, $legacy] = ($this->formerMember)();

    expect($legacy->owner_id)->toBe($carl->id)
        ->and($legacy->ownerLabel())->toBe('Carl Weber (no longer a member)');

    Livewire::test(ListDeals::class)
        ->assertTableColumnStateSet('owner_id', 'Carl Weber (no longer a member)', $legacy);

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $legacy->id])
        ->assertMountedActionModalSee('Carl Weber (no longer a member)');

    Livewire::test(DealDetailsWidget::class, ['record' => $legacy])
        ->assertSee('Carl Weber (no longer a member)');

    expect(DealOwnerOptions::forForm($legacy))->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
        ->and(DealOwnerOptions::forForm(null))->not->toHaveKey($carl->id);

    Livewire::test(EditDeal::class, ['record' => $legacy->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($legacy->fresh()->owner_id)->toBe($carl->id);
});

test('a former member cannot be given to another deal', function () {
    [$carl] = ($this->formerMember)();
    $retainer = ($this->makeDeal)('Retainer', $this->alice);

    Livewire::test(EditDeal::class, ['record' => $retainer->id])
        ->fillForm(['owner_id' => $carl->id])
        ->call('save')
        ->assertHasFormErrors(['owner_id' => 'in']);

    expect($retainer->fresh()->owner_id)->toBe($this->alice->id);
});

test('a member can reassign a deal', function () {
    $retainer = ($this->makeDeal)('Retainer', $this->alice);
    $this->actingAs($this->bob);
    Filament::setTenant($this->org);

    Livewire::test(EditDeal::class, ['record' => $retainer->id])
        ->fillForm(['owner_id' => $this->vera->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($retainer->fresh()->owner_id)->toBe($this->vera->id);
});

test('the my deals tab shows only the deals I own', function () {
    $mine = ($this->makeDeal)('Mine', $this->alice);
    $his = ($this->makeDeal)('His', $this->bob);
    $none = ($this->makeDeal)('None', null);

    Livewire::test(ListDeals::class)
        ->set('activeTab', 'mine')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$his, $none])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$mine, $his, $none]);
});

test('the owner filter narrows the table and lists former owners only when they own a deal', function () {
    $mine = ($this->makeDeal)('Mine', $this->alice);
    $his = ($this->makeDeal)('His', $this->bob);

    Livewire::test(ListDeals::class)
        ->filterTable('owner_id', $this->bob->id)
        ->assertCanSeeTableRecords([$his])
        ->assertCanNotSeeTableRecords([$mine]);

    $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);

    expect(DealOwnerOptions::forFilter())->not->toContain('Carl Weber (no longer a member)');

    ($this->makeDeal)('Legacy', $carl);

    expect(DealOwnerOptions::forFilter())->toHaveKey($carl->id, 'Carl Weber (no longer a member)');
});

test('another organization never leaks into the table, the tabs or the options', function () {
    $secret = ($this->makeDeal)('Globex secret', $this->eve, $this->eve->personalOrganization());

    expect(DealOwnerOptions::forForm(null))->not->toHaveKey($this->eve->id)
        ->and(DealOwnerOptions::forFilter())->not->toHaveKey($this->eve->id);

    Livewire::test(ListDeals::class)
        ->assertCanNotSeeTableRecords([$secret])
        ->set('activeTab', 'mine')
        ->assertCanNotSeeTableRecords([$secret]);

    Livewire::test(ListDeals::class)
        ->filterTable('owner_id', $this->eve->id)
        ->assertCanNotSeeTableRecords([$secret]);
});

test('deleting an owner account keeps the deal without an owner', function () {
    $his = ($this->makeDeal)('His', $this->bob);

    $this->bob->delete();

    expect(Deal::query()->find($his->id))->not->toBeNull()
        ->and($his->fresh()->owner_id)->toBeNull();
});

test('the deal page and the drawer show no owner for an unowned deal', function () {
    $none = ($this->makeDeal)('None', null);

    Livewire::test(DealDetailsWidget::class, ['record' => $none])->assertSee('No owner');

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $none->id])
        ->assertMountedActionModalSee('No owner');
});

test('an owner name is escaped on the deal page', function () {
    $owner = User::factory()->onboardingCompleted()->create(['name' => '<script>alert(1)</script>']);
    $this->org->members()->attach($owner, ['role' => OrganizationRole::Member->value]);
    $deal = ($this->makeDeal)('Scripted', $owner);

    Livewire::test(DealDetailsWidget::class, ['record' => $deal])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('the deals table does not query the owner of every row', function () {
    $countUserQueries = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "users"')) {
                $queries++;
            }
        });

        Livewire::test(ListDeals::class);

        return $queries;
    };

    ($this->makeDeal)('Deal 0', $this->bob);
    $withOne = $countUserQueries();

    foreach (range(1, 4) as $i) {
        ($this->makeDeal)("Deal {$i}", $this->vera);
    }

    expect($countUserQueries())->toBe($withOne);
});
