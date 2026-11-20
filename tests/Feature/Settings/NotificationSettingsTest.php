<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
});

test('the page shows the switch on by default', function () {
    $this->actingAs($this->ann)
        ->get(route('notification-settings.edit'))
        ->assertOk()
        ->assertSee('Email notifications')
        ->assertSee('Task reminder emails')
        ->assertSee('Notifications in the app are not affected.');

    Livewire::actingAs($this->ann)->test('pages::settings.notifications')->assertSet('taskReminderMails', true);
});

test('a new user reads the switch as on before any refresh', function () {
    expect(new User)->task_reminder_mails->toBeTrue()
        ->and(User::factory()->make()->task_reminder_mails)->toBeTrue()
        ->and($this->ann->task_reminder_mails)->toBeTrue()
        ->and($this->ann->fresh()->task_reminder_mails)->toBeTrue();
});

test('the switch is not mass assignable', function () {
    $user = User::create(['name' => 'Mass', 'email' => 'mass@example.com', 'password' => 'secret-pass-123', 'task_reminder_mails' => false]);

    expect($user->fresh()->task_reminder_mails)->toBeTrue()
        ->and((new User)->isFillable('task_reminder_mails'))->toBeFalse();
});

test('a user turns task reminder emails off, then on again', function () {
    $component = Livewire::actingAs($this->ann)->test('pages::settings.notifications')
        ->set('taskReminderMails', false)
        ->call('save')
        ->assertDispatched('notification-settings-updated');

    expect($this->ann->fresh()->task_reminder_mails)->toBeFalse();

    Livewire::actingAs($this->ann)->test('pages::settings.notifications')
        ->assertSet('taskReminderMails', false)
        ->set('taskReminderMails', true)
        ->call('save');

    expect($this->ann->fresh()->task_reminder_mails)->toBeTrue();
});

test('the settings sidebar links to the page', function () {
    $this->actingAs($this->ann)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('notification-settings.edit'), false)
        ->assertSee('Notifications');
});

test('saving changes only the signed-in user', function () {
    $bob = User::factory()->onboardingCompleted()->create(['email' => 'bob@example.com']);
    $before = $this->ann->only(['name', 'email', 'password', 'email_verified_at']);

    Livewire::actingAs($this->ann)->test('pages::settings.notifications')
        ->set('taskReminderMails', false)
        ->call('save');

    $ann = $this->ann->fresh();

    expect($bob->fresh()->task_reminder_mails)->toBeTrue()
        ->and($ann->task_reminder_mails)->toBeFalse()
        ->and($ann->only(['name', 'email', 'password', 'email_verified_at']))->toEqual($before);
});

test('a guest is sent to the login', function () {
    $this->get(route('notification-settings.edit'))->assertRedirect(route('login'));
});

test('an unverified user is sent to the verification notice', function () {
    $carl = User::factory()->unverified()->create(['email' => 'carl@example.com']);

    $this->actingAs($carl)->get(route('notification-settings.edit'))->assertRedirect(route('verification.notice'));

    expect($carl->fresh()->task_reminder_mails)->toBeTrue();
});

test('a forged array for the switch is refused and nothing is written', function (bool $debug) {
    config(['app.debug' => $debug]);

    Livewire::actingAs($this->ann)->test('pages::settings.notifications')->set('taskReminderMails', ['0'])->assertStatus(419);

    expect($this->ann->fresh()->task_reminder_mails)->toBeTrue()
        ->and(Hash::check('password', $this->ann->fresh()->password))->toBeTrue();
})->with([true, false]);

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
