<?php

use App\Enums\AccountSecurityEvent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Notifications\QueuedResetPassword;
use App\Support\Security\SecurityMails;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

const ASM_IP = '203.0.113.7';

function asmMailOf(AccountSecurityNotification $notification, object $notifiable): MailMessage
{
    return $notification->toMail($notifiable);
}

/**
 * @return list<AccountSecurityNotification>
 */
function asmSentTo(User $user): array
{
    return Notification::sent($user, AccountSecurityNotification::class)->all();
}

function asmSentOnDemandCount(): int
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatten(2)
        ->filter(fn ($entry): bool => ($entry['notification'] ?? null) instanceof AccountSecurityNotification)
        ->count();
}

function asmPanelProvider()
{
    return Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 14:05:00', 'UTC'));
    Notification::fake();
    $this->ann = User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('password')]);
});

function asmChangePassword(string $current = 'password', string $new = 'N3w-passw0rd!')
{
    return Livewire::test('pages::settings.password')
        ->set('current_password', $current)
        ->set('password', $new)
        ->set('password_confirmation', $new)
        ->call('updatePassword');
}

test('changing the password on the settings page mails the account', function () {
    $this->actingAs($this->ann);

    asmChangePassword()->assertHasNoErrors();

    Notification::assertSentToTimes($this->ann, AccountSecurityNotification::class, 1);
    $notification = asmSentTo($this->ann)[0];
    $mail = asmMailOf($notification, $this->ann);

    expect($notification->event)->toBe(AccountSecurityEvent::PasswordChanged)
        ->and($mail->subject)->toBe('Your password was changed')
        ->and($mail->introLines[0])->toBe('The password of your account was changed on 13 Nov 2026, 14:05 UTC from IP address 127.0.0.1.')
        ->and($mail->actionText)->toBe('Reset password')
        ->and($mail->actionUrl)->toBe(route('password.request'))
        ->and((string) $mail->render())->not->toContain('N3w-passw0rd!');
});

test('a wrong current password mails nothing', function () {
    $this->actingAs($this->ann);

    asmChangePassword('wrong-password')->assertHasErrors(['current_password']);

    Notification::assertNothingSent();
});

test('a password reset mails the account without the token', function () {
    $this->post(route('password.email'), ['email' => 'ann@example.com']);

    $token = null;
    Notification::assertSentTo($this->ann, QueuedResetPassword::class, function ($notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->withServerVariables(['REMOTE_ADDR' => ASM_IP])->post(route('password.update'), [
        'token' => $token,
        'email' => 'ann@example.com',
        'password' => 'N3w-passw0rd!',
        'password_confirmation' => 'N3w-passw0rd!',
    ])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->ann, AccountSecurityNotification::class, 1);
    $notification = asmSentTo($this->ann)[0];
    $rendered = (string) asmMailOf($notification, $this->ann)->render();

    expect($notification->event)->toBe(AccountSecurityEvent::PasswordChanged)
        ->and(asmMailOf($notification, $this->ann)->introLines[0])->toBe('The password of your account was changed on 13 Nov 2026, 14:05 UTC from IP address '.ASM_IP.'.')
        ->and($rendered)->not->toContain($token)->not->toContain('N3w-passw0rd!');
});

test('a bogus reset token mails nothing', function () {
    $this->post(route('password.update'), [
        'token' => 'bogus-token',
        'email' => 'ann@example.com',
        'password' => 'N3w-passw0rd!',
        'password_confirmation' => 'N3w-passw0rd!',
    ]);

    Notification::assertNotSentTo($this->ann, AccountSecurityNotification::class);
    expect(asmSentOnDemandCount())->toBe(0);
    expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue();
});

test('changing the email mails the old address only', function () {
    $this->ann->forceFill(['email' => 'Ann.Lee@example.com'])->save();
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.profile')
        ->set('email', 'ann.new@example.com')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        AccountSecurityNotification::class,
        fn (AccountSecurityNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ann.lee@example.com'
            && $n->event === AccountSecurityEvent::EmailChanged,
    );
    Notification::assertNotSentTo($this->ann, AccountSecurityNotification::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, AccountSecurityNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'ann.new@example.com');
    expect(asmSentOnDemandCount())->toBe(1);

    $notification = new AccountSecurityNotification(AccountSecurityEvent::EmailChanged, CarbonImmutable::now('UTC'), null);
    $mail = $notification->toMail($this->ann);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toBe('The email address of your account was changed')
        ->and($mail->actionText)->toBeNull()
        ->and($rendered)->toContain('Write to '.config('mail.from.address'))
        ->and($rendered)->not->toContain('ann.new@example.com');
});

test('changes that are not email changes mail nothing', function (array $change) {
    $this->actingAs($this->ann);

    $page = Livewire::test('pages::settings.profile');

    foreach ($change as $key => $value) {
        $page->set($key, $value);
    }

    $page->call('updateProfileInformation');

    Notification::assertNothingSent();
})->with([
    'name and email in another case' => [['name' => 'Ann', 'email' => 'ANN@example.com']],
    'name only' => [['name' => 'Ann B.']],
    'email change with a wrong password' => [['email' => 'ann.new@example.com', 'current_password' => 'wrong-password']],
]);

test('turning two-factor off mails the account', function () {
    $ann = User::factory()->withAppAuthentication()->create();

    asmPanelProvider()->saveSecret($ann, null);

    Notification::assertSentToTimes($ann, AccountSecurityNotification::class, 1);
    $notification = asmSentTo($ann)[0];
    expect($notification->event)->toBe(AccountSecurityEvent::TwoFactorDisabled)
        ->and(asmMailOf($notification, $ann)->subject)->toBe('Two-factor authentication was turned off')
        ->and(asmMailOf($notification, $ann)->actionUrl)->toBe(route('password.request'));
});

test('turning two-factor on mails nothing', function () {
    asmPanelProvider()->saveSecret($this->ann, 'JBSWY3DPEHPK3PXP');

    Notification::assertNothingSent();
});

test('a recovery code mails the account and a wrong one does not', function () {
    $ann = User::factory()->withAppAuthentication()->create();

    expect(asmPanelProvider()->verifyRecoveryCode('recovery-code-1', $ann))->toBeTrue();

    Notification::assertSentToTimes($ann, AccountSecurityNotification::class, 1);
    $notification = asmSentTo($ann)[0];
    $rendered = (string) asmMailOf($notification, $ann)->render();
    expect($notification->event)->toBe(AccountSecurityEvent::RecoveryCodeUsed)
        ->and($rendered)->toContain("That code can't be used again.")->not->toContain('recovery-code-1');

    expect(asmPanelProvider()->verifyRecoveryCode('not-a-code', $ann))->toBeFalse();

    Notification::assertSentToTimes($ann, AccountSecurityNotification::class, 1);
});

test('a recovery code used without a passed user mails the signed-in user', function () {
    $ann = User::factory()->withAppAuthentication()->create();
    $this->actingAs($ann);

    expect(asmPanelProvider()->verifyRecoveryCode('recovery-code-2'))->toBeTrue();

    Notification::assertSentToTimes($ann, AccountSecurityNotification::class, 1);
    expect(asmSentTo($ann)[0]->event)->toBe(AccountSecurityEvent::RecoveryCodeUsed);
});

test('the notification is queued after commit and mail only, and the IP is optional', function () {
    $notification = new AccountSecurityNotification(AccountSecurityEvent::PasswordChanged, CarbonImmutable::now('UTC'), null);

    expect($notification)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($notification->via($this->ann))->toBe(['mail'])
        ->and($notification->toMail($this->ann)->introLines[0])->toBe('The password of your account was changed on 13 Nov 2026, 14:05 UTC.');
});

test('every event has the exact mail text', function (AccountSecurityEvent $event, string $subject, array $lines) {
    $mail = (new AccountSecurityNotification($event, CarbonImmutable::now('UTC'), null))->toMail($this->ann);

    expect($mail->subject)->toBe($subject)
        ->and(array_slice($mail->introLines, 1))->toBe($lines);
})->with([
    [AccountSecurityEvent::PasswordChanged, 'Your password was changed', ['If this was you, you can ignore this email.', "If it wasn't, reset your password now: someone else may have access to your account."]],
    [AccountSecurityEvent::TwoFactorDisabled, 'Two-factor authentication was turned off', ['If this was you, you can ignore this email.', "If it wasn't, reset your password now and turn two-factor authentication back on."]],
    [AccountSecurityEvent::RecoveryCodeUsed, 'A two-factor recovery code was used', ["That code can't be used again.", 'If this was you, you can ignore this email.', "If it wasn't, reset your password now and generate new recovery codes."]],
]);

test('the toggle turns every mail off and the log lines stay', function () {
    config(['security.user_mails.enabled' => false]);
    $log = fakeSecurityLog();
    $this->actingAs($this->ann);

    asmChangePassword()->assertHasNoErrors();
    Livewire::test('pages::settings.profile')
        ->set('email', 'ann.new@example.com')
        ->set('current_password', 'N3w-passw0rd!')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    $bob = User::factory()->withAppAuthentication()->create();
    asmPanelProvider()->saveSecret($bob, null);

    Notification::assertNothingSent();
    $events = array_column(securityLogEntries($log), 'event');
    expect($events)->toContain('account.password_changed', 'account.email_changed', 'auth.two_factor_disabled');
});

test('someone repeating security actions cannot flood the mailbox', function () {
    $log = fakeSecurityLog();

    foreach (range(1, 11) as $ignored) {
        SecurityMails::send($this->ann, AccountSecurityEvent::PasswordChanged);
    }

    Notification::assertSentToTimes($this->ann, AccountSecurityNotification::class, 10);
    $capped = array_values(array_filter(securityLogEntries($log), fn (array $e): bool => $e['event'] === 'account.security_mail_capped'));
    expect($capped)->toHaveCount(1)
        ->and($capped[0]['level'])->toBe('warning')
        ->and($capped[0]['context'])->toMatchArray(['user_id' => $this->ann->id, 'event' => 'password_changed']);

    $this->travel(3601)->seconds();
    SecurityMails::send($this->ann, AccountSecurityEvent::TwoFactorDisabled);

    Notification::assertSentToTimes($this->ann, AccountSecurityNotification::class, 11);
});

test('the cap of one user does not touch another', function () {
    $bob = User::factory()->create();

    foreach (range(1, 11) as $ignored) {
        SecurityMails::send($this->ann, AccountSecurityEvent::PasswordChanged);
    }
    SecurityMails::send($bob, AccountSecurityEvent::PasswordChanged);

    Notification::assertSentToTimes($bob, AccountSecurityNotification::class, 1);
});

test('typing someone else address as the new email does not mail that address', function () {
    $carl = User::factory()->create(['email' => 'carl@example.com']);
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.profile')
        ->set('email', 'victim@example.org')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(AccountSecurityNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'ann@example.com');
    expect(asmSentOnDemandCount())->toBe(1);
    Notification::assertNotSentTo($carl, AccountSecurityNotification::class);
});

test('a broken queue never fails a password change', function () {
    Exceptions::fake();
    Notification::swap(Mockery::mock(ChannelManager::class, fn ($mock) => $mock
        ->shouldReceive('send')->andThrow(new RuntimeException('queue down'))
        ->getMock()));
    $this->actingAs($this->ann);

    asmChangePassword()->assertHasNoErrors();

    expect(Hash::check('N3w-passw0rd!', $this->ann->fresh()->password))->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

test('a real send reaches the mailer of the account with the exact subject', function () {
    Notification::swap(new ChannelManager(app()));
    $ann = User::factory()->withAppAuthentication()->create(['email' => 'real.ann@example.com']);

    SecurityMails::send($ann, AccountSecurityEvent::RecoveryCodeUsed);

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getSubject())->toBe('A two-factor recovery code was used')
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('real.ann@example.com');
});
