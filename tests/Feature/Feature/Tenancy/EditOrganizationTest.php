<?php

use App\Filament\Pages\Tenancy\EditOrganization;
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

test('organization name must be unique', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Livewire::test(EditOrganization::class)
        ->fillForm([
            'name' => $otherOrg->name,
        ])
        ->call('save')
        ->assertHasFormErrors(['name' => 'unique']);
});
