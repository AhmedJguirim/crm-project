<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
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

test('the page does not edit the name, email or password', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertDontSee('data.name', false)
        ->assertDontSee('data.email', false)
        ->assertDontSee('data.password', false);
});

test('a guest is sent to the panel login', function () {
    $this->get(Filament::getProfileUrl())->assertRedirect(Filament::getLoginUrl());
});

test('a forged save changes nothing', function () {
    $ann = User::factory()->onboardingCompleted()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $this->actingAs($ann);

    Livewire::test(EditProfile::class)
        ->set('data.name', 'Mallory')
        ->set('data.email', 'mallory@example.com')
        ->set('data.password', 'new-password-123')
        ->call('save')
        ->assertNotNotified();

    $ann->refresh();

    expect($ann->name)->toBe('Ann Lee')
        ->and($ann->email)->toBe('ann@example.com')
        ->and(Hash::check('password', $ann->password))->toBeTrue();
});

test('the settings nav links to the panel profile', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('href="'.Filament::getProfileUrl().'"', false)
        ->assertSee('Two-Factor Auth');
});
