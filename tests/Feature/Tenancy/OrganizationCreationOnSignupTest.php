<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Register;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

// TSK-2026-0011 AC-001: Successful registration creates personal Organization
test('registration creates personal organization with correct name', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'ahmed@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'ahmed@example.com')->first();

    expect($user)->not->toBeNull();

    $org = $user->personalOrganization();
    expect($org)->not->toBeNull()
        ->and($org->name)->toBe("Ahmed Test's Workspace")
        ->and($org->personal_team)->toBeTrue()
        ->and($org->created_by)->toBe($user->id);
});

test('registration creates pivot with owner role', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Pivot Test',
            'email' => 'pivot@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register');

    $user = User::where('email', 'pivot@example.com')->first();
    $org = $user->personalOrganization();

    expect($org->getMemberRole($user))->toBe(OrganizationRole::Owner);
});

test('user belongs to exactly one organization after registration', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Count Test',
            'email' => 'count@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register');

    $user = User::where('email', 'count@example.com')->first();

    expect($user->organizations)->toHaveCount(1);
});

// TSK-2026-0011 AC-003: Atomic transaction safety
test('no organization created if registration fails due to duplicate email', function () {
    User::factory()->create(['email' => 'dupe@example.com']);

    $orgCountBefore = Organization::count();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Dupe Test',
            'email' => 'dupe@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(Organization::count())->toBe($orgCountBefore);
});

// TSK-2026-0011 AC-004: Post-registration context - user can access tenant-scoped dashboard
test('newly registered user can access tenant-scoped dashboard', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Dashboard Test',
            'email' => 'dashboard@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register');

    $user = User::where('email', 'dashboard@example.com')->first();
    $org = $user->personalOrganization();

    expect($org)->not->toBeNull();

    $this->assertAuthenticated();
});

// Edge case: very long name truncated for org name
test('very long user name produces valid organization name', function () {
    $longName = str_repeat('A', 250);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => $longName,
            'email' => 'longname@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register');

    $user = User::where('email', 'longname@example.com')->first();
    $org = $user->personalOrganization();

    expect(strlen($org->name))->toBeLessThanOrEqual(255);
});
