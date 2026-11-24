<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a member sees the two-factor section, disabled', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Two-factor authentication (2FA)')
        ->assertSee('Authenticator app')
        ->assertSee('Disabled')
        ->assertSee('Set up');
});

test('a user with app authentication sees it enabled', function () {
    $user = User::factory()->onboardingCompleted()->withAppAuthentication()->create();

    $this->actingAs($user)->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Enabled')
        ->assertDontSee('JBSWY3DPEHPK3PXP');
});

test('the page edits the name, email and task mails, and has no password field in its form', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('data.name', false)
        ->assertSee('data.email', false)
        ->assertSee('data.task_reminder_mails', false)
        ->assertDontSee('data.password', false);
});

test('a guest is sent to the panel login', function () {
    $this->get(Filament::getProfileUrl())->assertRedirect(Filament::getLoginUrl());
});

test('a forged save cannot change the password or the verification fields', function () {
    $verifiedAt = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');
    $ann = User::factory()->onboardingCompleted()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'email_verified_at' => $verifiedAt]);
    $this->actingAs($ann);

    Livewire::test(EditProfile::class)
        ->set('data.name', 'Ann')
        ->set('data.password', 'new-password-123')
        ->set('data.email_verified_at', null)
        ->set('data.pending_email', 'mallory@example.com')
        ->call('save')
        ->assertHasNoFormErrors();

    $ann->refresh();

    expect(Hash::check('password', $ann->password))->toBeTrue()
        ->and(Hash::check('new-password-123', $ann->password))->toBeFalse()
        ->and($ann->email_verified_at->equalTo($verifiedAt))->toBeTrue()
        ->and($ann->pending_email)->toBeNull();
});

test('the settings nav links to the panel profile', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('href="'.Filament::getProfileUrl().'"', false)
        ->assertSee('Two-Factor Auth');
});
