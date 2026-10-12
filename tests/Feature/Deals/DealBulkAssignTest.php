<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
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

    $this->d1 = ($this->makeDeal)('D1', $this->alice);
    $this->d2 = ($this->makeDeal)('D2', null);
    $this->d3 = ($this->makeDeal)('D3', $this->bob);
});

test('deals are assigned to a member in one step', function () {
    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d1, $this->d2], ['owner_id' => $this->bob->id])
        ->assertNotified('Assigned 2 deals to Bob Stone');

    expect($this->d1->fresh()->owner_id)->toBe($this->bob->id)
        ->and($this->d2->fresh()->owner_id)->toBe($this->bob->id)
        ->and($this->d3->fresh()->owner_id)->toBe($this->bob->id)
        ->and(Deal::query()->where('title', 'D3')->firstOrFail()->updated_at)->toEqual($this->d3->updated_at);
});

test('one deal is worded in the singular and a deal that already has the owner is counted', function () {
    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d2], ['owner_id' => $this->alice->id])
        ->assertNotified('Assigned 1 deal to Alice Martin')
        ->callTableBulkAction('assignOwner', [$this->d1, $this->d2], ['owner_id' => $this->alice->id])
        ->assertNotified('Assigned 2 deals to Alice Martin');
});

test('leaving the owner empty clears it', function () {
    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d1, $this->d3], ['owner_id' => null])
        ->assertNotified('Removed the owner of 2 deals');

    expect($this->d1->fresh()->owner_id)->toBeNull()
        ->and($this->d3->fresh()->owner_id)->toBeNull();
});

test('a forged owner outside the members is refused', function (string $forged) {
    $value = match ($forged) {
        'other organization' => $this->eve->id,
        'letters' => 'abc',
        'unknown id' => 99999999,
    };

    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d1], ['owner_id' => $value])
        ->assertHasTableBulkActionErrors(['owner_id' => 'in']);

    expect($this->d1->fresh()->owner_id)->toBe($this->alice->id);
})->with(['other organization', 'letters', 'unknown id']);

test('a former member is not an option and cannot be assigned', function () {
    $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
    $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->detach($carl->id);

    ($this->makeDeal)('Legacy', $carl);

    $page = Livewire::test(ListDeals::class)
        ->mountTableBulkAction('assignOwner', [$this->d1]);

    $select = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getComponent('owner_id');

    expect($select->getOptions())->toBe([
        $this->alice->id => 'Alice Martin',
        $this->bob->id => 'Bob Stone',
        $this->vera->id => 'Vera Viewer',
    ]);

    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d1], ['owner_id' => $carl->id])
        ->assertHasTableBulkActionErrors(['owner_id' => 'in']);
});

test('who sees the bulk action follows the edit rule', function (string $user, bool $visible) {
    $this->actingAs($this->{$user});
    Filament::setTenant($this->org);

    $page = Livewire::test(ListDeals::class);

    $visible ? $page->assertTableBulkActionVisible('assignOwner') : $page->assertTableBulkActionHidden('assignOwner');
})->with([
    'owner' => ['alice', true],
    'member' => ['bob', true],
    'viewer' => ['vera', false],
]);

test('a viewer cannot run the bulk action through a forged call', function () {
    $this->actingAs($this->vera);
    Filament::setTenant($this->org);

    Livewire::test(ListDeals::class)
        ->call('mountTableBulkAction', 'assignOwner', [(string) $this->d1->id])
        ->assertActionNotMounted()
        ->call('callMountedTableBulkAction', ['owner_id' => $this->vera->id]);

    expect($this->d1->fresh()->owner_id)->toBe($this->alice->id);
});

test('deals of another organization are never touched', function () {
    $spy = ($this->makeDeal)('Spy', $this->eve, $this->eve->personalOrganization());

    Livewire::test(ListDeals::class)
        ->assertCanNotSeeTableRecords([$spy])
        ->callTableBulkAction('assignOwner', Deal::query()->get(), ['owner_id' => $this->bob->id]);

    expect($spy->fresh()->owner_id)->toBe($this->eve->id);

    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$spy], ['owner_id' => $this->bob->id]);

    expect($spy->fresh()->owner_id)->toBe($this->eve->id);
});

test('the update goes through the model so the segments of the contact are resynced', function () {
    Segment::factory()->for($this->org)->published()->create();
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->d1->forceFill(['contact_id' => $contact->id])->saveQuietly();

    Queue::fake();

    Livewire::test(ListDeals::class)
        ->callTableBulkAction('assignOwner', [$this->d1], ['owner_id' => $this->bob->id]);

    Queue::assertPushed(ResyncContactSegments::class);
});
