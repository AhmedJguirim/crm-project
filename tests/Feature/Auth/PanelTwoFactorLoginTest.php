<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The panel login at the password step, ready for the code step.
 */
function panelLoginAtChallenge(User $user): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');
}

/**
 * A six digit code that is not the current one.
 */
function wrongAppCode(User $user): string
{
    $current = (int) AppAuthentication::make()->getCurrentCode($user);

    return str_pad((string) (($current + 1) % 1000000), 6, '0', STR_PAD_LEFT);
}

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->withAppAuthentication()->create(['email' => 'ann@example.com']);
});

test('the password alone does not sign in a user with app authentication', function () {
    panelLoginAtChallenge($this->ann)
        ->assertNoRedirect()
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));

    $this->assertGuest();
});

test('the right app code signs in', function () {
    panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($this->ann))
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($this->ann);
});

test('a wrong code is refused', function () {
    panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.code', wrongAppCode($this->ann))
        ->call('authenticate')
        ->assertNoRedirect();

    $this->assertGuest();
});

test('a wrong code shows the invalid code message', function () {
    $page = panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.code', wrongAppCode($this->ann))
        ->call('authenticate');

    expect($page->errors()->get('data.multiFactor.app.code'))->toBe(['The code you entered is invalid.']);
});

test('a recovery code works once', function () {
    panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'recovery-code-1')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($this->ann);
    expect($this->ann->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(1);

    auth()->logout();

    $page = panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'recovery-code-1')
        ->call('authenticate');

    expect($page->errors()->get('data.multiFactor.app.recoveryCode'))->toBe(['The recovery code you entered is invalid.']);
    $this->assertGuest();
});

test('a code of the wrong shape is a validation error, not a 500', function (mixed $code) {
    $page = panelLoginAtChallenge($this->ann)
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertNoRedirect();

    expect($page->errors()->get('data.multiFactor.app.code'))->not->toBe([]);
    $this->assertGuest();
})->with([
    'letters' => 'abcdef',
    'too short' => '12',
    'blank' => '',
]);

test('the challenge is limited and the lockout is logged', function () {
    $log = fakeSecurityLog();
    $page = panelLoginAtChallenge($this->ann);

    foreach (range(1, 6) as $attempt) {
        $page->set('data.multiFactor.app.code', wrongAppCode($this->ann))->call('authenticate');
    }

    $page->assertNotified();
    $this->assertGuest();

    $lockouts = array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === 'auth.lockout'));
    expect($lockouts)->not->toBe([])
        ->and($lockouts[0]['context'])->toMatchArray(['source' => 'panel']);
});

test('the right code is refused once the challenge is limited', function () {
    $page = panelLoginAtChallenge($this->ann);

    foreach (range(1, 6) as $attempt) {
        $page->set('data.multiFactor.app.code', wrongAppCode($this->ann))->call('authenticate');
    }

    $page->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($this->ann))
        ->call('authenticate')
        ->assertNoRedirect();

    $this->assertGuest();
});

test('the onboarding flag waits for the second factor', function () {
    $bob = User::factory()->onboardingNotCompleted()->withAppAuthentication()->create();

    $page = panelLoginAtChallenge($bob);

    expect(session('show_onboarding'))->toBeNull();

    $page->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($bob))
        ->call('authenticate')
        ->assertRedirect(Filament::getUrl());

    expect(session('show_onboarding'))->toBeTrue();
});

test('a user without app authentication signs in with the password alone', function () {
    $carl = User::factory()->onboardingCompleted()->create();

    panelLoginAtChallenge($carl)->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($carl);
});

test('the old two-factor routes are gone', function () {
    expect(Route::has('two-factor.show'))->toBeFalse()
        ->and(Route::has('two-factor.login'))->toBeFalse()
        ->and(Route::has('two-factor.enable'))->toBeFalse();

    $this->actingAs($this->ann)->get('/settings/two-factor')->assertNotFound();
    $this->get('/two-factor-challenge')->assertNotFound();
});

test('the secret and the recovery codes never serialize', function () {
    $raw = DB::table('users')->where('id', $this->ann->id)->first();

    expect($this->ann->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes'])
        ->and($raw->app_authentication_secret)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($raw->app_authentication_secret)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($raw->app_authentication_recovery_codes)->not->toContain('recovery-code-1');
});

test('the old password-only login post signs nobody in', function () {
    $this->post('/login', ['email' => 'ann@example.com', 'password' => 'password'])
        ->assertStatus(303)
        ->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
});
