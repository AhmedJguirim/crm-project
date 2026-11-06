<?php

use App\Enums\InviteStatus;
use App\Filament\Pages\Auth\Register;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\LegalDocuments;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

const TERMS_REFUSAL_MESSAGE = 'You must agree to the Terms of Service to create an account.';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10 09:30:00', 'UTC'));
});

/**
 * @return array<string, mixed>
 */
function termsSignUpFields(string $email = 'nora.field@example.com'): array
{
    return [
        'name' => 'Nora Field',
        'email' => $email,
        'password' => 'password',
        'passwordConfirmation' => 'password',
    ];
}

test('signing up with the box ticked records the acceptance', function () {
    Livewire::test(Register::class)
        ->fillForm(termsSignUpFields() + ['accept_terms' => true])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'nora.field@example.com')->sole();

    expect($user->terms_accepted_at->toDateTimeString())->toBe('2026-11-10 09:30:00')
        ->and($user->terms_version)->toBe('2026-11-06');

    $this->assertAuthenticatedAs($user);
});

test('signing up without accepting is refused', function (?callable $tamper) {
    $component = Livewire::test(Register::class)->fillForm(termsSignUpFields());

    if ($tamper) {
        $tamper($component);
    }

    $component->call('register');

    expect($component->errors()->first('data.accept_terms'))->toBe(TERMS_REFUSAL_MESSAGE)
        ->and(User::query()->where('email', 'nora.field@example.com')->exists())->toBeFalse();

    $this->assertGuest();
})->with([
    'omitted' => [null],
    'false' => [fn ($component) => $component->set('data.accept_terms', false)],
    'string 0' => [fn ($component) => $component->set('data.accept_terms', '0')],
    'string no' => [fn ($component) => $component->set('data.accept_terms', 'no')],
    'forged array' => [fn ($component) => $component->set('data.accept_terms', ['1'])],
]);

test('an invited guest must tick the box too', function () {
    $invite = OrganizationInvite::factory()->create(['email' => 'invitee@example.com']);

    $this->withSession(['invite_id' => $invite->id, 'invite_email' => 'invitee@example.com']);

    $component = Livewire::test(Register::class)
        ->fillForm(termsSignUpFields('invitee@example.com'))
        ->call('register');

    expect($component->errors()->first('data.accept_terms'))->toBe(TERMS_REFUSAL_MESSAGE)
        ->and(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(session('invite_id'))->toBe($invite->id);

    $component->fillForm(termsSignUpFields('invitee@example.com') + ['accept_terms' => true])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'invitee@example.com')->sole();

    expect($invite->organization->hasMember($user))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted)
        ->and($user->terms_version)->toBe('2026-11-06')
        ->and($user->terms_accepted_at->toDateTimeString())->toBe('2026-11-10 09:30:00');
});

test('the version constant matches both documents', function (string $file) {
    $firstLine = strtok(file_get_contents(resource_path("markdown/{$file}")), "\n");

    expect($firstLine)->toBe('Last updated: '.Carbon::parse(LegalDocuments::VERSION)->format('j F Y'));
})->with(['terms.md', 'privacy.md']);

test('the terms acceptance cannot be mass assigned', function () {
    $user = new User(['name' => 'Eve', 'terms_accepted_at' => now(), 'terms_version' => 'forged']);

    expect($user->terms_accepted_at)->toBeNull()
        ->and($user->terms_version)->toBeNull();
});

test('users created without signing up have no acceptance record', function () {
    $this->artisan('demo:create-account', ['name' => 'Demo Owner', 'email' => 'demo.owner@example.com'])
        ->expectsQuestion('Password', 'Demo-password-2026')
        ->expectsQuestion('Confirm the password', 'Demo-password-2026')
        ->assertSuccessful();

    $user = User::query()->where('email', 'demo.owner@example.com')->sole();

    expect($user->terms_accepted_at)->toBeNull()
        ->and($user->terms_version)->toBeNull()
        ->and(User::factory()->create()->fresh()->terms_version)->toBeNull();
});

test('existing users without a record are not blocked', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->actingAs($user)
        ->get(Filament::getUrl($user->personalOrganization()))
        ->assertOk();
});
