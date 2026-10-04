<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('edit organization page loads', function () {
    Livewire::test(EditOrganization::class)
        ->assertOk();
});

test('edit organization page shows current org name', function () {
    Livewire::test(EditOrganization::class)
        ->assertFormFieldExists('name')
        ->assertFormSet([
            'name' => $this->org->name,
        ]);
});

test('organization name can be updated', function () {
    Livewire::test(EditOrganization::class)
        ->fillForm([
            'name' => 'Updated Org Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->org->fresh()->name)->toBe('Updated Org Name');
});

test('organization name is required', function () {
    Livewire::test(EditOrganization::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['name' => 'required']);
});

test('organization description can be updated', function () {
    Livewire::test(EditOrganization::class)
        ->fillForm([
            'description' => 'A new description for the org.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->org->fresh()->description)->toBe('A new description for the org.');
});

describe('organization names are unique per user', function () {
    beforeEach(function () {
        $this->globex = Organization::factory()->create(['name' => 'Globex']);
        $this->globex->members()->attach($this->user, ['role' => OrganizationRole::Member->value]);
    });

    it('saves the settings with an unchanged name', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['name' => $this->org->name, 'description' => 'Same name'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->description)->toBe('Same name');
    });

    it('refuses a rename to another organization of the user', function (string $name) {
        Livewire::test(EditOrganization::class)
            ->fillForm(['name' => $name])
            ->call('save')
            ->assertHasFormErrors(['name' => 'You already belong to an organization with this name.']);
    })->with(['exact' => 'Globex', 'case and spaces' => ' globex ']);

    it('allows a rename to the name of another customer', function () {
        $bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $initech = $bob->personalOrganization();
        $initech->update(['name' => 'Initech']);

        Livewire::test(EditOrganization::class)
            ->fillForm(['name' => 'Initech'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->name)->toBe('Initech')
            ->and($initech->fresh()->name)->toBe('Initech');
    });
});
