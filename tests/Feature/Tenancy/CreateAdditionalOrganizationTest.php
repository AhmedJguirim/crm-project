<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($this->user);

    Filament::setTenant($this->user->personalOrganization());
});

// TSK-2026-0013 AC-001: Successful creation of new Organization
test('user can create additional organization', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Client XYZ Project',
            'description' => 'A test project for client XYZ.',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $org = Organization::where('name', 'Client XYZ Project')->first();

    expect($org)->not->toBeNull()
        ->and($org->personal_team)->toBeFalse()
        ->and($org->created_by)->toBe($this->user->id)
        ->and($org->description)->toBe('A test project for client XYZ.');

    expect($org->getMemberRole($this->user))->toBe(OrganizationRole::Owner);
});

test('new organization appears in user tenants', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'New Workspace',
        ])
        ->call('register');

    $this->user->refresh();

    expect($this->user->organizations)->toHaveCount(2);
});

// TSK-2026-0013 AC-002: Validation errors on creation
test('organization creation requires name', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Organization::where('personal_team', false)->count())->toBe(0);
});

test('organization creation rejects duplicate name', function () {
    Organization::factory()->create([
        'name' => 'Existing Org',
        'created_by' => $this->user->id,
    ]);

    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Existing Org',
        ])
        ->call('register')
        ->assertHasFormErrors(['name']);
});

// TSK-2026-0013 AC-003: Optional fields handling
test('organization can be created with only name', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Minimal Org',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $org = Organization::where('name', 'Minimal Org')->first();

    expect($org)->not->toBeNull()
        ->and($org->description)->toBeNull()
        ->and($org->logo_path)->toBeNull();
});

// TSK-2026-0013 AC-004: Immediate reflection in switcher
test('created organization is accessible as tenant', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Switcher Test Org',
        ])
        ->call('register');

    $org = Organization::where('name', 'Switcher Test Org')->first();

    expect($this->user->canAccessTenant($org))->toBeTrue();

    $this->get(Filament::getUrl($org))
        ->assertOk();
});

// Edge case: very long name
test('organization name is limited to 255 characters', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => str_repeat('a', 256),
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'max']);
});
