<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\UniqueUserEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile page links back to the panel and no longer shows the starter-kit pages', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    $panelUrl = filament()->getUrl();

    expect($panelUrl)->toEndWith('/admin/acme');

    $response = $this->get(route('profile.edit'))->assertOk();

    expect(substr_count($response->getContent(), 'href="'.$panelUrl.'"'))->toBeGreaterThanOrEqual(2);

    $response
        ->assertDontSee('/settings/appearance', false)
        ->assertDontSee('livewire-starter-kit', false);
});

test('the settings sidebar shows the panel brand, not the starter-kit name', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('>CRM</div>', false)
        ->assertDontSee('Laravel Starter Kit', false)
        ->assertDontSee('Starter Kit', false);
});

test('the settings sidebar brand follows the panel brand name', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    filament()->getDefaultPanel()->brandName('Acme Sales');

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('>Acme Sales</div>', false)
        ->assertDontSee('>CRM</div>', false);
});

test('the settings sidebar prints a hostile panel brand name escaped', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    filament()->getDefaultPanel()->brandName('<script>alert(1)</script>');

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('an already verified user resending the verification mail is sent to the panel without a mail', function () {
    Notification::fake();
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->call('resendVerificationNotification')
        ->assertRedirect(filament()->getUrl());

    Notification::assertNothingSent();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('current_password', 'password')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->not->toEqual('test@example.com');
    expect($user->pending_email)->toEqual('test@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

// SEC-18: emails are stored lower-case
test('a changed profile email is stored lower-case', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann')
        ->set('email', 'Ann.New@Example.COM')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('ann@example.com')
        ->and($user->fresh()->pending_email)->toBe('ann.new@example.com');
});

test('an address taken in another case is refused on the profile page', function () {
    User::factory()->create(['email' => 'bob@example.com']);
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann')
        ->set('email', 'BOB@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);

    expect($user->fresh()->email)->toBe('ann@example.com');
});

// BUG-65
test('the profile page refuses an address stored in another case with the plain message', function () {
    User::factory()->create(['email' => 'Ann.Lee@Example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);

    $this->actingAs($bob);

    $page = Livewire::test('pages::settings.profile')
        ->set('email', 'ann.lee@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);

    expect($page->errors()->get('email'))->toBe(['The email has already been taken.'])
        ->and($bob->fresh()->email)->toBe('bob@example.com');
});

test('a user stored with capitals saves the profile and keeps the verification', function () {
    $verifiedAt = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');
    $ann = User::factory()->create(['email' => 'Ann.Lee@Example.com', 'email_verified_at' => $verifiedAt]);

    $this->actingAs($ann);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann L.')
        ->set('email', 'Ann.Lee@Example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $ann->refresh();

    expect($ann->name)->toBe('Ann L.')
        ->and($ann->email)->toBe('ann.lee@example.com')
        ->and($ann->email_verified_at->equalTo($verifiedAt))->toBeTrue();
});

test('a real address change keeps the verification until the new address is confirmed', function () {
    $ann = User::factory()->create(['email' => 'Ann.Lee@Example.com']);

    $this->actingAs($ann);

    Livewire::test('pages::settings.profile')
        ->set('email', 'ann.new@example.com')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($ann->fresh()->email_verified_at)->not->toBeNull()
        ->and($ann->fresh()->pending_email)->toBe('ann.new@example.com');
});

test('the unique email rule leaves a forged non-string value to the string rule and ignores the given user', function () {
    $ann = User::factory()->create(['email' => 'Ann.Lee@Example.com']);

    $forged = Validator::make(['email' => ['ann.lee@example.com']], ['email' => ['string', new UniqueUserEmail]]);
    $own = Validator::make(['email' => 'ANN.lee@example.com'], ['email' => [new UniqueUserEmail($ann->id)]]);
    $other = Validator::make(['email' => 'ANN.lee@example.com'], ['email' => [new UniqueUserEmail]]);

    expect($forged->fails())->toBeTrue()
        ->and($forged->errors()->keys())->toBe(['email'])
        ->and($own->passes())->toBeTrue()
        ->and($other->fails())->toBeTrue();
});

describe('SEC-03: the current password is required to change the email', function () {
    beforeEach(function () {
        $this->verifiedAt = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');
        $this->ann = User::factory()->create(['email' => 'ann@example.com', 'email_verified_at' => $this->verifiedAt]);
        $this->actingAs($this->ann);
    });

    test('an email change without a password is refused', function () {
        $log = fakeSecurityLog();

        $page = Livewire::test('pages::settings.profile')
            ->set('email', 'ann.new@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);

        $ann = $this->ann->fresh();

        expect($page->errors()->get('current_password'))->toBe(['The current password field is required.'])
            ->and($ann->email)->toBe('ann@example.com')
            ->and($ann->email_verified_at->equalTo($this->verifiedAt))->toBeTrue()
            ->and(securityLogEntries($log))->toBe([]);
    });

    test('an email change with a wrong password is refused and the field is cleared', function () {
        $page = Livewire::test('pages::settings.profile')
            ->set('email', 'ann.new@example.com')
            ->set('current_password', 'wrong-password')
            ->call('updateProfileInformation')
            ->assertHasErrors(['current_password'])
            ->assertSet('current_password', '');

        expect($page->errors()->get('current_password'))->toBe(['The password is incorrect.'])
            ->and($this->ann->fresh()->email)->toBe('ann@example.com');
    });

    test('an email change with the right password works and clears the field', function () {
        $log = fakeSecurityLog();

        Livewire::test('pages::settings.profile')
            ->set('email', 'Ann.New@Example.COM')
            ->set('current_password', 'password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertSet('current_password', '');

        $ann = $this->ann->fresh();

        expect($ann->email)->toBe('ann@example.com')
            ->and($ann->pending_email)->toBe('ann.new@example.com')
            ->and(securityLogEntries($log))->toHaveCount(1)
            ->and(securityLogEntries($log)[0]['event'])->toBe('account.email_change_requested');
    });

    test('a name-only change needs no password', function () {
        Livewire::test('pages::settings.profile')
            ->set('name', 'Ann L.')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        expect($this->ann->fresh()->name)->toBe('Ann L.');
    });

    test('a name-only change with a stray wrong password is not checked and is cleared', function () {
        Livewire::test('pages::settings.profile')
            ->set('name', 'Ann L.')
            ->set('current_password', 'wrong-password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertSet('current_password', '');

        expect($this->ann->fresh()->name)->toBe('Ann L.');
    });

    test('a case-only change needs no password and keeps the verification', function () {
        $lee = User::factory()->create(['email' => 'Ann.Lee@Example.com', 'email_verified_at' => $this->verifiedAt]);
        $this->actingAs($lee);

        Livewire::test('pages::settings.profile')
            ->set('email', 'ANN.LEE@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $lee->refresh();

        expect($lee->email)->toBe('ann.lee@example.com')
            ->and($lee->email_verified_at->equalTo($this->verifiedAt))->toBeTrue();
    });

    test("another user's password does not count", function () {
        User::factory()->create(['email' => 'bob@example.com', 'password' => 'bob-secret-123']);

        $page = Livewire::test('pages::settings.profile')
            ->set('email', 'ann.new@example.com')
            ->set('current_password', 'bob-secret-123')
            ->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);

        expect($page->errors()->get('current_password'))->toBe(['The password is incorrect.'])
            ->and($this->ann->fresh()->email)->toBe('ann@example.com');
    });

    test('a padded password is not the password', function () {
        Livewire::test('pages::settings.profile')
            ->set('email', 'ann.new@example.com')
            ->set('current_password', ' password ')
            ->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);

        expect($this->ann->fresh()->email)->toBe('ann@example.com');
    });

    test('a taken address without a password shows both errors', function () {
        User::factory()->create(['email' => 'bob@example.com']);

        Livewire::test('pages::settings.profile')
            ->set('email', 'bob@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email', 'current_password']);
    });

    test('the profile form shows the current password field', function () {
        $page = Livewire::test('pages::settings.profile')
            ->assertSee('Current password')
            ->assertSee('Required only when you change your email.');

        preg_match('/<input\b[^>]*wire:model="current_password"[^>]*>/s', $page->html(), $input);

        expect($input)->toHaveCount(1)
            ->and($input[0])->toContain('type="password"')->toContain('autocomplete="current-password"');
    });
});
