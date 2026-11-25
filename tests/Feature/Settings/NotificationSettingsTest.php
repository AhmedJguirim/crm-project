<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
});

test('the settings sidebar links to the page', function () {
    $this->actingAs($this->ann)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('notification-settings.edit'), false)
        ->assertSee('Notifications');
});

test('a guest is sent to the login', function () {
    $this->get(route('notification-settings.edit'))->assertRedirect(route('login'));
});

test('an unverified user is sent to the verification notice', function () {
    $carl = User::factory()->unverified()->create(['email' => 'carl@example.com']);

    $this->actingAs($carl)->get(route('notification-settings.edit'))->assertRedirect(route('verification.notice'));

    expect($carl->fresh()->task_reminder_mails)->toBeTrue();
});

test('a forged array over raw http answers 419 and a legitimate update still works', function () {
    config(['app.debug' => true]);
    $this->actingAs($this->ann);

    $snapshot = json_encode(Livewire::test('pages::settings.notifications')->snapshot);

    $send = fn (array $updates, array $calls = []) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ], ['X-Livewire' => 'true']);

    $send(['taskReminderMails' => ['0']], [['path' => '', 'method' => 'save', 'params' => []]])->assertStatus(419);

    expect($this->ann->fresh()->task_reminder_mails)->toBeTrue();

    $send(['taskReminderMails' => false], [['path' => '', 'method' => 'save', 'params' => []]])->assertOk();

    expect($this->ann->fresh()->task_reminder_mails)->toBeFalse();
});
