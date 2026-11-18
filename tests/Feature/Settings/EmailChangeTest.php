<?php

use App\Enums\AccountSecurityEvent;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Notifications\ConfirmNewEmailAddress;
use App\Notifications\QueuedVerifyEmail;
use App\Support\Auth\EmailChanges;
use App\Support\Auth\VerificationMails;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

const EC_REFUSAL = 'You have asked for 5 verification emails in the last 24 hours. Try again later.';

function ecAsk(string $email, ?string $name = null, string $password = 'password')
{
    $page = Livewire::test('pages::settings.profile');

    if ($name !== null) {
        $page->set('name', $name);
    }

    return $page->set('email', $email)->set('current_password', $password)->call('updateProfileInformation');
}

function ecSecurityMailCount(): int
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatten(2)
        ->filter(fn ($entry): bool => ($entry['notification'] ?? null) instanceof AccountSecurityNotification)
        ->count();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00', 'UTC'));
    Notification::fake();
    $this->verifiedAt = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => $this->verifiedAt,
    ]);
    $this->actingAs($this->ann);
});

test('saving a new email keeps the current one and mails a link to the new one', function () {
    $log = fakeSecurityLog();

    ecAsk('Ann.New@Example.COM')
        ->assertHasNoErrors()
        ->assertSet('email', 'ann@example.com')
        ->assertSet('current_password', '')
        ->assertSee('We sent a link to ann.new@example.com to confirm your new email address. Your email stays ann@example.com until you open it.');

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann@example.com')
        ->and($ann->pending_email)->toBe('ann.new@example.com')
        ->and($ann->email_verified_at->equalTo($this->verifiedAt))->toBeTrue();

    Notification::assertSentOnDemandTimes(ConfirmNewEmailAddress::class, 1);
    Notification::assertSentOnDemand(ConfirmNewEmailAddress::class, function (ConfirmNewEmailAddress $n, array $channels, object $notifiable): bool {
        return $notifiable->routes['mail'] === 'ann.new@example.com'
            && $n instanceof ShouldQueueAfterCommit
            && $n instanceof ShouldBeEncrypted
            && str_starts_with($n->url, route('user-email.confirm', ['id' => $this->ann->id, 'hash' => sha1('ann.new@example.com')]));
    });
    Notification::assertNotSentTo(new AnonymousNotifiable, AccountSecurityNotification::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, QueuedVerifyEmail::class);
    Notification::assertNothingSentTo($ann);

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('account.email_change_requested')
        ->and($entries[0]['context'])->toMatchArray(['user_id' => $ann->id])
        ->and(json_encode($entries))->not->toContain('ann.new')->not->toContain('ann@example.com');

    $this->get(ContactResource::getUrl('index', tenant: $ann->organizations()->first()))->assertOk();
});

test('a name change is saved with the email change', function () {
    ecAsk('ann.new@example.com', 'Ann Lee')->assertHasNoErrors();

    expect($this->ann->fresh())->name->toBe('Ann Lee')->email->toBe('ann@example.com');
});

test('opening the link swaps the address, verifies it and tells the old address', function () {
    ecAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    $log = fakeSecurityLog();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC'));

    $this->get($url)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('email-change-status', 'Your email address is now ann.new@example.com.');

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann.new@example.com')
        ->and($ann->pending_email)->toBeNull()
        ->and($ann->email_verified_at->equalTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC')))->toBeTrue();

    Notification::assertSentOnDemandTimes(AccountSecurityNotification::class, 1);
    Notification::assertSentOnDemand(AccountSecurityNotification::class, fn (AccountSecurityNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ann@example.com'
        && $n->event === AccountSecurityEvent::EmailChanged);

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('account.email_changed')
        ->and($entries[0]['context'])->toMatchArray(['user_id' => $ann->id])
        ->and(json_encode($entries))->not->toContain('ann.new')->not->toContain('ann@example.com');

    $this->get(route('profile.edit'))->assertOk()->assertSee('Your email address is now ann.new@example.com.');
});

test('opening the link twice changes nothing more', function () {
    ecAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];

    $this->get($url);
    $log = fakeSecurityLog();
    $this->get($url)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('email-change-status', 'Your email address is now ann.new@example.com.')
        ->assertSessionMissing('email-change-error');

    expect(ecSecurityMailCount())->toBe(1)
        ->and(securityLogEntries($log))->toBe([])
        ->and($this->ann->fresh()->email)->toBe('ann.new@example.com');
});

test('an expired link brings the user back with a message', function () {
    ecAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:01:00', 'UTC'));

    $this->get($url)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('email-change-error', 'This link has expired or is not valid. Ask for a new one below.');

    expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
    expect(ecSecurityMailCount())->toBe(0);
});

test('a newer request voids the older link', function () {
    ecAsk('ann.new@example.com');
    ecAsk('ann.other@example.com');

    $this->get(emailChangeUrlsSentTo('ann.new@example.com')[0])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('email-change-error', EmailChanges::NO_LONGER_VALID);

    expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.other@example.com');
    expect(ecSecurityMailCount())->toBe(0);
});

test('cancelling voids the link', function () {
    ecAsk('ann.new@example.com');
    $log = fakeSecurityLog();

    Livewire::test('pages::settings.profile')
        ->call('cancelEmailChange')
        ->assertSee('The email change was cancelled. Your email stays ann@example.com.');

    expect($this->ann->fresh()->pending_email)->toBeNull()
        ->and(array_column(securityLogEntries($log), 'event'))->toBe(['account.email_change_cancelled']);

    $this->get(emailChangeUrlsSentTo('ann.new@example.com')[0])
        ->assertSessionHas('email-change-error', EmailChanges::NO_LONGER_VALID);
    expect($this->ann->fresh()->email)->toBe('ann@example.com');
});

test('sending the link again mails the pending address and both links work', function () {
    ecAsk('ann.new@example.com');

    Livewire::test('pages::settings.profile')
        ->call('resendEmailChangeLink')
        ->assertHasNoErrors()
        ->assertSee('A new link has been sent to ann.new@example.com.');

    $urls = emailChangeUrlsSentTo('ann.new@example.com');
    expect($urls)->toHaveCount(2);

    $this->get($urls[0])->assertSessionHas('email-change-status', 'Your email address is now ann.new@example.com.');
    expect($this->ann->fresh()->email)->toBe('ann.new@example.com');
});

test('an address taken by someone else in the meantime is refused', function () {
    ecAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    User::factory()->create(['email' => 'Ann.New@example.com']);

    $this->get($url)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('email-change-error', EmailChanges::TAKEN);

    expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBeNull();
    expect(ecSecurityMailCount())->toBe(0);
});

test('an unverified user can fix a typo, and the never-verified old address is not mailed', function () {
    $this->ann->forceFill(['email' => 'ann@exmaple.com', 'email_verified_at' => null])->save();

    ecAsk('ann@example.com')->assertHasNoErrors();
    $this->get(emailChangeUrlsSentTo('ann@example.com')[0])->assertSessionHas('email-change-status');

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann@example.com')->and($ann->email_verified_at)->not->toBeNull();
    expect(ecSecurityMailCount())->toBe(0);
    $this->get(ContactResource::getUrl('index', tenant: $ann->organizations()->first()))->assertOk();
});

test('the link cap is shared with the verification mails and a refusal saves nothing', function () {
    foreach (range(1, 5) as $ignored) {
        expect(VerificationMails::claim($this->ann))->toBeNull();
    }

    $page = ecAsk('ann.new@example.com', 'Ann Lee');

    expect($page->errors()->get('email'))->toBe([EC_REFUSAL])
        ->and($this->ann->fresh())->pending_email->toBeNull()->name->toBe('Ann');
    Notification::assertNothingSent();
    $page->assertSet('current_password', '');
});

test('the mail text', function () {
    $mail = (new ConfirmNewEmailAddress('https://example.test/x'))->toMail(new AnonymousNotifiable);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toBe('Confirm your new email address')
        ->and($mail->actionText)->toBe('Confirm email address')
        ->and($mail->actionUrl)->toBe('https://example.test/x')
        ->and($rendered)->toContain('This link expires in 60 minutes.')
        ->toContain('If you did not ask for this, ignore this email: nothing changes.')
        ->not->toContain('Ann')->not->toContain('ann@example.com');
});

test('the page shows the session messages escaped', function () {
    $this->withSession(['email-change-error' => '<script>alert(1)</script>'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('the pending address is lower-cased even when the field is not', function () {
    config(['fortify.lowercase_usernames' => false]);

    ecAsk('Ann.New@Example.COM')->assertHasNoErrors();

    expect($this->ann->fresh()->pending_email)->toBe('ann.new@example.com');
});

test('cancelling with nothing pending logs nothing', function () {
    $log = fakeSecurityLog();

    EmailChanges::cancel($this->ann);

    expect(securityLogEntries($log))->toBe([]);
});

describe('hostile cases', function () {
    test('a user opening another user link', function () {
        ecAsk('ann.new@example.com');
        $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
        $eve = User::factory()->create(['email' => 'eve@example.com']);

        $this->actingAs($eve)->get($url)->assertForbidden();

        expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
        expect($eve->fresh()->email)->toBe('eve@example.com');
        expect(ecSecurityMailCount())->toBe(0);
    });

    test('a guest opening a confirm link is sent to sign in', function () {
        $this->ann->forceFill(['pending_email' => 'ann.new@example.com'])->save();
        $url = URL::temporarySignedRoute('user-email.confirm', now()->addHour(), ['id' => $this->ann->id, 'hash' => sha1('ann.new@example.com')]);
        auth()->logout();

        $this->get($url)->assertRedirect(route('login'));

        expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
    });

    test('a forged hash breaks the signature', function () {
        ecAsk('ann.new@example.com');
        $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
        $parts = explode('?', $url, 2);
        $forged = preg_replace('/[0-9a-f]{40}$/', sha1('mallory@example.com'), $parts[0]).'?'.$parts[1];

        $this->get($forged)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('email-change-error', 'This link has expired or is not valid. Ask for a new one below.');

        expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
    });

    test('a signed link for an address that was never pending is refused', function () {
        ecAsk('ann.new@example.com');
        $url = URL::temporarySignedRoute('user-email.confirm', now()->addHour(), ['id' => $this->ann->id, 'hash' => sha1('mallory@example.com')]);

        $this->get($url)->assertSessionHas('email-change-error', EmailChanges::NO_LONGER_VALID);

        expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
    });

    test('junk route parameters answer 404', function (string $id, string $hash) {
        $this->get("/settings/email/confirm/{$id}/{$hash}")->assertNotFound();
    })->with([
        'letters' => ['abc', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
        'negative' => ['-1', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
        '19 digits' => ['9999999999999999999', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
        '20 digits' => ['99999999999999999999', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
        'bad hash' => ['1', 'not-a-hash'],
    ]);

    test('forging resend and cancel with nothing pending does nothing', function () {
        $log = fakeSecurityLog();
        $page = Livewire::test('pages::settings.profile');

        foreach (range(1, 10) as $ignored) {
            $page->call('resendEmailChangeLink')->assertDontSee('A new link has been sent');
        }
        $page->call('cancelEmailChange')->assertDontSee('The email change was cancelled');

        Notification::assertNothingSent();
        expect(securityLogEntries($log))->toBe([])
            ->and(RateLimiter::attempts("verification-mails:user:{$this->ann->id}"))->toBe(0);
    });

    test('mail-bombing an outside address stops at 5 links', function () {
        $page = Livewire::test('pages::settings.profile');

        foreach (range(1, 10) as $attempt) {
            $page->set('email', 'victim@example.org')->set('current_password', 'password')->call('updateProfileInformation');

            if ($attempt > 5) {
                expect($page->errors()->get('email'))->toBe([EC_REFUSAL]);
            }
        }

        expect(emailChangeUrlsSentTo('victim@example.org'))->toHaveCount(5);
    });

    test('an email change without the current password is refused', function () {
        $page = Livewire::test('pages::settings.profile')
            ->set('email', 'ann.new@example.com')
            ->set('current_password', '')
            ->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);

        expect($page->errors()->has('email'))->toBeFalse()
            ->and($this->ann->fresh()->pending_email)->toBeNull();
        Notification::assertNothingSent();
    });

    test('a resend that hits the cap is refused with the same message and sends nothing', function () {
        ecAsk('ann.new@example.com');

        foreach (range(1, 4) as $ignored) {
            VerificationMails::claim($this->ann);
        }

        $page = Livewire::test('pages::settings.profile')->call('resendEmailChangeLink');

        expect($page->errors()->get('email'))->toBe([EC_REFUSAL])
            ->and(emailChangeUrlsSentTo('ann.new@example.com'))->toHaveCount(1);
    });
});
