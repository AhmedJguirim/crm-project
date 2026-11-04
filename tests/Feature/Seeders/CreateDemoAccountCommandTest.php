<?php

use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * Runs the command with the password typed at the hidden prompts.
 */
function createDemoAccount(object $test, string $email, string $password = 'Demo-password-2026', ?string $confirmation = null): int
{
    return $test->artisan('demo:create-account', ['name' => 'Jane Demo', 'email' => $email])
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm the password', $confirmation ?? $password)
        ->run();
}

/**
 * Counts rows of a tenant-scoped model in one organization.
 *
 * @param  class-string<Contact|Deal>  $model
 */
function demoCount(Organization $organization, string $model): int
{
    return app(TenantContext::class)->run($organization->id, fn (): int => $model::query()->count());
}

test('it creates a verified login owning a workspace filled with the demo data', function () {
    expect(createDemoAccount($this, 'jane@acme.test'))->toBe(0);

    $user = User::query()->where('email', 'jane@acme.test')->sole();
    $organization = $user->personalOrganization();
    $roles = $organization->members()->pluck('organization_user.role', 'email');

    expect(Hash::check('Demo-password-2026', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->onboarding_completed)->toBeTrue()
        ->and(demoCount($organization, Contact::class))->toBe(20)
        ->and(demoCount($organization, Deal::class))->toBe(19)
        ->and($roles->sort()->values()->all())->toBe([OrganizationRole::Admin->value, OrganizationRole::Member->value, OrganizationRole::Owner->value])
        ->and($roles->keys()->reject(fn (string $email): bool => $email === 'jane@acme.test')->every(fn (string $email): bool => str_ends_with($email, '@demo.invalid')))->toBeTrue();
});

test('it creates none of the dev seed accounts with known passwords', function () {
    createDemoAccount($this, 'jane@acme.test');

    expect(User::query()->where('email', 'like', '%@example.com')->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%@demo.invalid')->get()->every(fn (User $teammate): bool => ! Hash::check('password', $teammate->password)))->toBeTrue();
});

test('two demo accounts get their own copy of the demo data', function () {
    createDemoAccount($this, 'jane@acme.test');
    createDemoAccount($this, 'john@acme.test');

    $organizations = User::query()->whereIn('email', ['jane@acme.test', 'john@acme.test'])->get()->map->personalOrganization();

    expect($organizations->map(fn (Organization $organization): int => demoCount($organization, Contact::class))->all())->toBe([20, 20])
        ->and($organizations->map->id->unique())->toHaveCount(2);
});

test('it creates nothing when the input is invalid', function (string $email, string $password, string $confirmation) {
    User::factory()->create(['email' => 'taken@acme.test']);
    $users = User::query()->count();

    expect(createDemoAccount($this, $email, $password, $confirmation))->toBe(1)
        ->and(User::query()->count())->toBe($users)
        ->and(Organization::query()->count())->toBe(0);
})->with([
    'email taken' => ['taken@acme.test', 'Demo-password-2026', 'Demo-password-2026'],
    'not an email' => ['not-an-email', 'Demo-password-2026', 'Demo-password-2026'],
    'passwords differ' => ['jane@acme.test', 'Demo-password-2026', 'Other-password-2026'],
    'password too short' => ['jane@acme.test', 'short', 'short'],
]);
