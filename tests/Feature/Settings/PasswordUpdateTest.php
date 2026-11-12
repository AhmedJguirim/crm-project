<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('changing your own password keeps you signed in on this device', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'password' => Hash::make('old-password-1'),
    ]);
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $guard->hashPasswordForCookie($user->password)])
        ->get('/settings/profile')
        ->assertOk();

    Livewire::test('pages::settings.password')
        ->set('current_password', 'old-password-1')
        ->set('password', 'new-password-22')
        ->set('password_confirmation', 'new-password-22')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $user->refresh();

    expect(session('password_hash_web'))->toBe($guard->hashPasswordForCookie($user->password));

    $this->get(Filament::getUrl($user->personalOrganization()))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('the success message says the other devices are signed out', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

    $this->actingAs($user);

    Livewire::test('pages::settings.password')->assertSee('Saved. You are signed out on your other devices.');
});
