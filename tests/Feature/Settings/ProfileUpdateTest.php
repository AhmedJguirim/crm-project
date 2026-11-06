<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
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
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('ann.new@example.com');
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
