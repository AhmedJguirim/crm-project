<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Acme']);
    $this->globex = Organization::factory()->create(['name' => 'Globex']);

    $this->dan = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'dan@example.com']);
    $this->acme->members()->attach($this->dan->id, ['role' => OrganizationRole::Member->value]);

    $this->danAccepted = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'Dan@Example.com',
        'status' => InviteStatus::Accepted,
    ]);
    $this->danPending = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'dan@example.com',
        'status' => InviteStatus::Pending,
    ]);
    $this->danGlobex = OrganizationInvite::factory()->create([
        'organization_id' => $this->globex->id,
        'email' => 'dan@example.com',
        'status' => InviteStatus::Pending,
    ]);
    $this->erin = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'erin@example.com',
        'status' => InviteStatus::Pending,
    ]);
});

test('deleteInvitesFor removes every matching invite of that organization only', function () {
    $this->acme->deleteInvitesFor($this->dan);

    expect(OrganizationInvite::query()->whereKey($this->danAccepted->id)->exists())->toBeFalse()
        ->and(OrganizationInvite::query()->whereKey($this->danPending->id)->exists())->toBeFalse()
        ->and(OrganizationInvite::query()->whereKey($this->danGlobex->id)->exists())->toBeTrue()
        ->and(OrganizationInvite::query()->whereKey($this->erin->id)->exists())->toBeTrue();
});

test('the leave organization user menu action uses it', function () {
    $this->actingAs($this->dan);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    expect($this->acme->members()->whereKey($this->dan->id)->exists())->toBeFalse()
        ->and(OrganizationInvite::query()->where('organization_id', $this->acme->id)->whereEmailIgnoringCase('dan@example.com')->count())->toBe(0)
        ->and(OrganizationInvite::query()->whereKey($this->danGlobex->id)->exists())->toBeTrue()
        ->and(OrganizationInvite::query()->whereKey($this->erin->id)->exists())->toBeTrue();
});

test('a wrong confirmation name leaves the membership and the invites alone', function () {
    $this->actingAs($this->dan);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'acme')
        ->call('callMountedAction')
        ->assertHasErrors(['mountedActions.0.data.confirmation']);

    expect($this->acme->members()->whereKey($this->dan->id)->exists())->toBeTrue()
        ->and(OrganizationInvite::query()->whereKey($this->danPending->id)->exists())->toBeTrue();
});
