<?php

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\User;
use App\Notifications\ConfirmNewEmailAddress;
use App\Notifications\QueuedVerifyEmail;
use App\Support\Auth\VerificationMails;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

function pevPanel(): Panel
{
    return Filament::getPanel('admin');
}

/**
 * @return list<string>
 */
function pevNotificationTitles(): array
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications->map(fn (FilamentNotification $notification): string => (string) $notification->getTitle())->values()->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00', 'UTC'));
    $this->ann = User::factory()->unverified()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'ann@example.com', 'password' => Hash::make('password')]);
    $this->annOrganization = $this->ann->organizations()->first();
});

test('an unverified user is sent to the prompt from every panel page', function () {
    $this->actingAs($this->ann)
        ->get(ContactResource::getUrl('index', tenant: $this->annOrganization))
        ->assertRedirect(pevPanel()->getEmailVerificationPromptUrl());

    $this->actingAs($this->ann)
        ->get(pevPanel()->getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertSee('ann@example.com');
});

test('a verified user is not stopped', function () {
    $this->ann->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($this->ann)->get(ContactResource::getUrl('index', tenant: $this->annOrganization))->assertOk();
});

test('signing up sends one queued, encrypted verification mail to the panel link', function () {
    Notification::fake();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'Str0ng-passw0rd!',
            'passwordConfirmation' => 'Str0ng-passw0rd!',
            'accept_terms' => true,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $bob = User::query()->where('email', 'bob@example.com')->firstOrFail();

    Notification::assertSentToTimes($bob, QueuedVerifyEmail::class, 1);
    Notification::assertSentTo($bob, QueuedVerifyEmail::class, function (QueuedVerifyEmail $notification) use ($bob): bool {
        $prefix = route('filament.admin.auth.email-verification.verify', ['id' => $bob->id, 'hash' => sha1('bob@example.com')]);

        return $notification instanceof ShouldQueue
            && $notification instanceof ShouldBeEncrypted
            && str_starts_with($notification->url, $prefix);
    });
    expect($bob->email_verified_at)->toBeNull();
});

test('the mailed link verifies the account and logs it', function () {
    $log = fakeSecurityLog();

    $this->actingAs($this->ann)
        ->get(pevPanel()->getVerifyEmailUrl($this->ann))
        ->assertRedirect(Filament::getUrl());

    expect($this->ann->fresh()->email_verified_at)->not->toBeNull();

    $entries = collect(securityLogEntries($log))->where('event', 'account.email_verified')->values();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context']['user_id'])->toBe($this->ann->id)
        ->and(json_encode($entries[0]['context']))->not->toContain('ann@example.com');
});

test('opening the link a second time changes nothing', function () {
    $url = pevPanel()->getVerifyEmailUrl($this->ann);

    $this->actingAs($this->ann)->get($url)->assertRedirect(Filament::getUrl());
    $verifiedAt = $this->ann->fresh()->email_verified_at;

    Event::fake([Verified::class]);
    $this->travel(5)->minutes();

    $this->get($url)->assertRedirect(Filament::getUrl());

    Event::assertNotDispatched(Verified::class);
    expect($this->ann->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
});

test('an expired link brings the user back to the prompt with a message', function () {
    $url = pevPanel()->getVerifyEmailUrl($this->ann);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:01:00', 'UTC'));

    $this->actingAs($this->ann)->get($url)
        ->assertRedirect(pevPanel()->getEmailVerificationPromptUrl());

    $notifications = session('filament.notifications');
    expect($notifications[0]['title'])->toBe('This verification link has expired or is not valid.')
        ->and($this->ann->fresh()->email_verified_at)->toBeNull();
});

test('a tampered link is treated like an expired one', function () {
    $url = pevPanel()->getVerifyEmailUrl($this->ann).'&x=1';
    $tampered = preg_replace('/signature=[^&]+/', 'signature=x', pevPanel()->getVerifyEmailUrl($this->ann));

    foreach ([$url, $tampered] as $link) {
        $this->actingAs($this->ann)->get($link)->assertRedirect(pevPanel()->getEmailVerificationPromptUrl());
    }

    expect($this->ann->fresh()->email_verified_at)->toBeNull();
});

test('an already verified user opening an expired link goes to the panel', function () {
    $url = pevPanel()->getVerifyEmailUrl($this->ann);
    $this->ann->forceFill(['email_verified_at' => now()])->save();
    $this->travel(2)->hours();

    $this->actingAs($this->ann)->get($url)->assertRedirect(pevPanel()->getUrl());
});

test('another signed route keeps its 403 on a bad signature', function () {
    $this->ann->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($this->ann)
        ->get(route('exports.download', ['file' => 'x.csv', 'user' => $this->ann->id, 'signature' => 'broken']))
        ->assertForbidden();
});

test('resending from the prompt is capped at 5 mails a day', function () {
    Notification::fake();
    $this->actingAs($this->ann);
    Filament::setCurrentPanel(pevPanel());

    foreach (range(1, VerificationMails::PER_DAY) as $ignored) {
        expect(VerificationMails::send($this->ann))->toBeNull();
    }

    Livewire::test(EmailVerificationPrompt::class)
        ->callAction('resendNotification');

    expect(pevNotificationTitles())->toBe(['You have asked for 5 verification emails in the last 24 hours. Try again later.']);

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 5);
});

test('resending from the prompt under the cap sends the mail', function () {
    Notification::fake();
    $this->actingAs($this->ann);
    Filament::setCurrentPanel(pevPanel());

    Livewire::test(EmailVerificationPrompt::class)
        ->callAction('resendNotification')
        ->assertNotified(FilamentNotification::make()->success()->title("We've resent the email."));

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 1);
});

test('the cap window ends after 24 hours', function () {
    Notification::fake();

    foreach (range(1, VerificationMails::PER_DAY) as $ignored) {
        VerificationMails::send($this->ann);
    }
    expect(VerificationMails::send($this->ann))->toBeString()
        ->and(RateLimiter::attempts("verification-mails:user:{$this->ann->id}"))->toBe(5);
    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 5);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:59:00', 'UTC'));
    expect(VerificationMails::send($this->ann))->toBeString();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:01', 'UTC'));

    expect(VerificationMails::send($this->ann))->toBeNull();
    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 6);
});

test('a verified user is never mailed', function () {
    Notification::fake();
    $this->ann->forceFill(['email_verified_at' => now()])->save();

    expect(VerificationMails::send($this->ann))->toBeNull();

    Notification::assertNothingSent();
    expect(RateLimiter::attempts("verification-mails:user:{$this->ann->id}"))->toBe(0);
});

test('the settings page resend is capped too', function () {
    Notification::fake();
    $this->actingAs($this->ann);

    foreach (range(1, VerificationMails::PER_DAY) as $ignored) {
        VerificationMails::send($this->ann);
    }

    $page = Livewire::test('pages::settings.profile')
        ->call('resendVerificationNotification')
        ->assertHasErrors(['email'])
        ->assertDontSee('A new verification link has been sent to your email address.');

    expect($page->errors()->get('email'))->toBe(['You have asked for 5 verification emails in the last 24 hours. Try again later.']);

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 5);
});

test('the settings page resend under the cap sends one mail', function () {
    Notification::fake();
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.profile')
        ->call('resendVerificationNotification')
        ->assertHasNoErrors()
        ->assertSee('A new verification link has been sent to your email address.');

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 1);
});

test('changing the email on the settings page mails a confirmation link to the new address and keeps the account verified', function () {
    Notification::fake();
    $this->ann->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.profile')
        ->set('name', $this->ann->name)
        ->set('email', 'ann.new@example.com')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann@example.com')->and($ann->pending_email)->toBe('ann.new@example.com')->and($ann->email_verified_at)->not->toBeNull();

    Notification::assertNothingSentTo($ann);
    Notification::assertNotSentTo(new AnonymousNotifiable, QueuedVerifyEmail::class);
    Notification::assertSentOnDemand(ConfirmNewEmailAddress::class, fn (ConfirmNewEmailAddress $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ann.new@example.com'
        && str_contains($n->url, sha1('ann.new@example.com')));

    $this->get(ContactResource::getUrl('index', tenant: $this->annOrganization))->assertOk();
});

test('changing only the name mails no verification link', function () {
    Notification::fake();
    $this->ann->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann Renamed')
        ->set('email', 'ann@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
    expect($this->ann->fresh()->email_verified_at)->not->toBeNull();
});

test('the mailed link of a real send verifies the account', function () {
    Notification::fake();

    VerificationMails::send($this->ann);

    $url = Notification::sent($this->ann, QueuedVerifyEmail::class)->first()->url;
    $this->actingAs($this->ann)->get($url)->assertRedirect(Filament::getUrl());

    expect($this->ann->fresh()->email_verified_at)->not->toBeNull();
});

test('the Fortify resend route sends one mail under the cap', function () {
    Notification::fake();

    $this->actingAs($this->ann)->post(route('verification.send'));

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 1);
});

test('the Fortify resend route goes through the cap too', function () {
    Notification::fake();

    foreach (range(1, VerificationMails::PER_DAY) as $ignored) {
        VerificationMails::send($this->ann);
    }

    $this->actingAs($this->ann)->post(route('verification.send'));

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 5);
});

// Hostile cases

test('a user opening another user\'s verification link gets a 403', function () {
    $eve = User::factory()->unverified()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'eve@example.com']);

    $this->actingAs($eve)->get(pevPanel()->getVerifyEmailUrl($this->ann))->assertForbidden();

    expect($this->ann->fresh()->email_verified_at)->toBeNull()->and($eve->fresh()->email_verified_at)->toBeNull();
});

test('a link made for the old address after an email change gets a 403', function () {
    $oldLink = pevPanel()->getVerifyEmailUrl($this->ann);
    $this->ann->forceFill(['email' => 'ann.new@example.com'])->save();

    $this->actingAs($this->ann->fresh())->get($oldLink)->assertForbidden();

    expect($this->ann->fresh()->email_verified_at)->toBeNull();
});

test('a guest opening a verification link is sent to the login', function () {
    $this->get(pevPanel()->getVerifyEmailUrl($this->ann))->assertRedirect(Filament::getLoginUrl());

    expect($this->ann->fresh()->email_verified_at)->toBeNull();
});

test('an unverified user forging the old unthrottled resend 10 times gets 5 mails', function () {
    Notification::fake();
    $this->actingAs($this->ann);

    $page = Livewire::test('pages::settings.profile');
    foreach (range(1, 10) as $ignored) {
        $page->call('resendVerificationNotification');
    }

    Notification::assertSentToTimes($this->ann, QueuedVerifyEmail::class, 5);
});

test('the mail is built for the panel link and carries the signed url only', function () {
    $notification = new QueuedVerifyEmail;
    $notification->url = pevPanel()->getVerifyEmailUrl($this->ann);

    $mail = $notification->toMail($this->ann);

    expect($mail->actionUrl)->toBe($notification->url)
        ->and(URL::hasValidSignature(Request::create($mail->actionUrl)))->toBeTrue();
});
