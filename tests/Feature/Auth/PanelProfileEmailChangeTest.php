<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Notifications\ConfirmNewEmailAddress;
use App\Support\Auth\EmailChanges;
use App\Support\Auth\VerificationMails;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Security\SecurityPayloads;

const PPE_REFUSAL = 'You have asked for 5 verification emails in the last 24 hours. Try again later.';

function ppeAsk(string $email, ?string $name = null, ?string $password = 'password')
{
    $page = Livewire::test(EditProfile::class)->set('data.email', $email);

    if ($name !== null) {
        $page->set('data.name', $name);
    }

    if ($password !== null) {
        $page->set('data.currentPassword', $password);
    }

    return $page->call('save');
}

function ppeForgetClaimedNotifications(): void
{
    session()->forget('filament.claimed_notifications');
}

function ppeSentCount(string $address): int
{
    return count(emailChangeUrlsSentTo($address));
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

test('the page shows the profile section above 2FA', function () {
    $html = $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('<title', false)
        ->assertSee('Update your name and email address')
        ->assertSee('Name')
        ->assertSee('Email address')
        ->assertSee('Save changes')
        ->assertSee('Authenticator app')
        ->assertDontSee('Email change pending')
        ->getContent();

    expect($html)->toMatch('/<title>\s*Profile/')
        ->and(strpos($html, 'Update your name and email address'))->toBeLessThan(strpos($html, 'Authenticator app'));
});

test('a name change is saved without a password', function () {
    Livewire::test(EditProfile::class)
        ->set('data.name', 'Ann Lee')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved');

    expect($this->ann->fresh())->name->toBe('Ann Lee')->email->toBe('ann@example.com')->pending_email->toBeNull();
});

test('a case-only change needs no password and keeps the verification', function () {
    $this->ann->forceFill(['email' => 'Ann.Lee@Example.com'])->save();

    Livewire::test(EditProfile::class)
        ->set('data.email', 'ann.lee@example.com')
        ->call('save')
        ->assertHasNoFormErrors();

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann.lee@example.com')
        ->and($ann->pending_email)->toBeNull()
        ->and($ann->email_verified_at->equalTo($this->verifiedAt))->toBeTrue();
    Notification::assertNothingSent();
});

test('a new address is held pending and mailed', function () {
    $log = fakeSecurityLog();

    ppeAsk('Ann.New@Example.COM', 'Ann Lee')
        ->assertHasNoFormErrors()
        ->assertNotified('Check ann.new@example.com for a link to confirm your new email address.')
        ->assertSet('data.email', 'ann@example.com')
        ->assertSee('Email change pending')
        ->assertSee('We sent a link to ann.new@example.com to confirm your new email address. Your email stays ann@example.com until you open it.');

    expect($this->ann->fresh())->name->toBe('Ann Lee')->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
    Notification::assertSentOnDemandTimes(ConfirmNewEmailAddress::class, 1);
    expect(ppeSentCount('ann.new@example.com'))->toBe(1);

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('account.email_change_requested')
        ->and(json_encode($entries))->not->toContain('ann.new');
});

test('a new address without the current password is refused', function () {
    ppeAsk('ann.new@example.com', password: null)->assertHasFormErrors(['currentPassword']);

    expect($this->ann->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

test('a wrong current password is refused', function () {
    ppeAsk('ann.new@example.com', password: 'wrong')->assertHasFormErrors(['currentPassword']);

    expect($this->ann->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

test('an address used by another account in another case is refused', function () {
    User::factory()->create(['email' => 'Bob@Example.com']);

    $page = ppeAsk('bob@example.com');

    expect($page->errors()->get('data.email'))->toBe(['The email has already been taken.'])
        ->and($this->ann->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

test('the mail cap refuses and saves nothing, not even the name', function () {
    foreach (range(1, 5) as $ignored) {
        expect(VerificationMails::claim($this->ann))->toBeNull();
    }

    $page = ppeAsk('ann.new@example.com', 'Ann Lee');

    expect($page->errors()->get('data.email'))->toBe([PPE_REFUSAL])
        ->and($this->ann->fresh())->name->toBe('Ann')->pending_email->toBeNull();
    Notification::assertNothingSent();
});

test('sending the link again', function () {
    ppeAsk('ann.new@example.com');

    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('resendEmailChangeLink')->schemaComponent('pendingEmailChange', 'content'))
        ->assertNotified('A new link has been sent to ann.new@example.com.');

    $urls = emailChangeUrlsSentTo('ann.new@example.com');
    expect($urls)->toHaveCount(2);

    $this->get($urls[0])->assertRedirect(Filament::getPanel('admin')->getProfileUrl());
    expect($this->ann->fresh()->email)->toBe('ann.new@example.com');
});

test('sending the link again is refused once the mail cap is used up', function () {
    ppeAsk('ann.new@example.com');

    foreach (range(1, 4) as $ignored) {
        expect(VerificationMails::claim($this->ann))->toBeNull();
    }

    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('resendEmailChangeLink')->schemaComponent('pendingEmailChange', 'content'))
        ->assertNotified(FilamentNotification::make()->danger()->title(PPE_REFUSAL));

    expect(ppeSentCount('ann.new@example.com'))->toBe(1);
});

test('cancelling the change', function () {
    ppeAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    $log = fakeSecurityLog();
    ppeForgetClaimedNotifications();

    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('cancelEmailChange')->schemaComponent('pendingEmailChange', 'content'))
        ->assertNotified('The email change was cancelled. Your email stays ann@example.com.')
        ->assertDontSee('Email change pending');

    expect($this->ann->fresh()->pending_email)->toBeNull()
        ->and(array_column(securityLogEntries($log), 'event'))->toBe(['account.email_change_cancelled']);
    ppeForgetClaimedNotifications();

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getProfileUrl());
    FilamentNotification::assertNotified(EmailChanges::NO_LONGER_VALID);
});

test('cancelling when the change is already gone does nothing', function () {
    ppeAsk('ann.new@example.com');
    $page = Livewire::test(EditProfile::class);
    $page->call('mountAction', 'cancelEmailChange', [], ['schemaComponent' => 'content.pendingEmailChange']);
    $this->ann->forceFill(['pending_email' => null])->save();
    ppeForgetClaimedNotifications();
    $log = fakeSecurityLog();

    $page->call('callMountedAction');

    expect(securityLogEntries($log))->toBe([])
        ->and(session('filament.notifications'))->toBeNull()
        ->and(session('filament.claimed_notifications'))->toBeNull();
});

test('the confirm link lands on the panel profile', function () {
    ppeAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    ppeForgetClaimedNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC'));

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getProfileUrl());
    FilamentNotification::assertNotified('Your email address is now ann.new@example.com.');

    $ann = $this->ann->fresh();
    expect($ann->email)->toBe('ann.new@example.com')
        ->and($ann->email_verified_at->equalTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC')))->toBeTrue();
});

test('an expired link lands on the panel profile with a message', function () {
    ppeAsk('ann.new@example.com');
    $url = emailChangeUrlsSentTo('ann.new@example.com')[0];
    ppeForgetClaimedNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:01:00', 'UTC'));

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getProfileUrl());
    FilamentNotification::assertNotified('This link has expired or is not valid. Ask for a new one below.');

    expect($this->ann->fresh())->email->toBe('ann@example.com')->pending_email->toBe('ann.new@example.com');
});

describe('hostile', function () {
    test('a forged array for the name is refused without a 500', function () {
        Livewire::test(EditProfile::class)
            ->set('data.name', ['x'])
            ->call('save')
            ->assertHasFormErrors(['name']);

        expect($this->ann->fresh()->name)->toBe('Ann');
    });

    test('a forged array for the email or the current password is refused without a 500', function () {
        Livewire::test(EditProfile::class)
            ->set('data.email', ['mallory@example.com'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        Livewire::test(EditProfile::class)
            ->set('data.email', 'ann.new@example.com')
            ->set('data.currentPassword', ['password'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        expect($this->ann->fresh()->pending_email)->toBeNull();
        Notification::assertNothingSent();
    });

    test('a name of 256 characters is refused and 255 is kept', function () {
        Livewire::test(EditProfile::class)->set('data.name', str_repeat('a', 256))->call('save')->assertHasFormErrors(['name']);
        expect($this->ann->fresh()->name)->toBe('Ann');

        Livewire::test(EditProfile::class)->set('data.name', str_repeat('a', 255))->call('save')->assertHasNoFormErrors();
        expect($this->ann->fresh()->name)->toBe(str_repeat('a', 255));
    });

    test('an email of 256 characters is refused', function () {
        ppeAsk(str_repeat('a', 244).'@example.com')->assertHasFormErrors(['email']);

        expect($this->ann->fresh()->pending_email)->toBeNull();
        Notification::assertNothingSent();
    });

    test('a sixth save within a minute is throttled', function () {
        foreach (range(1, 6) as $i) {
            Livewire::test(EditProfile::class)->set('data.name', "Ann {$i}")->call('save');
        }

        $claimed = session('filament.claimed_notifications');

        expect($claimed)->toHaveCount(1)
            ->and($claimed[0]['title'])->toStartWith('Too many requests.')
            ->and($claimed[0]['status'])->toBe('danger');
        expect($this->ann->fresh()->name)->toBe('Ann 5');
    });

    test('a guest cannot open the page', function () {
        auth()->logout();

        $this->get(Filament::getProfileUrl())->assertRedirect(Filament::getLoginUrl());
    });

    test('the current password does not stay in the page after a change, so a second change asks for it again', function () {
        $page = ppeAsk('ann.new@example.com')->assertHasNoFormErrors()->assertSet('data.currentPassword', null);

        $page->set('data.email', 'mallory@example.com')->call('save')->assertHasFormErrors(['currentPassword']);

        expect($this->ann->fresh()->pending_email)->toBe('ann.new@example.com')
            ->and(ppeSentCount('mallory@example.com'))->toBe(0);
    });

    test('a refused change also clears the typed current password', function () {
        User::factory()->create(['email' => 'bob@example.com']);

        ppeAsk('bob@example.com')->assertHasFormErrors(['email'])->assertSet('data.currentPassword', null);

        expect($this->ann->fresh()->pending_email)->toBeNull();
    });

    test('a hostile pending address is shown as text', function () {
        $payload = 'x<script>alert(1)</script>@example.com';
        $this->ann->forceFill(['pending_email' => $payload])->save();

        $html = $this->get(Filament::getProfileUrl())->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>');
        SecurityPayloads::assertHtmlIsInert($html, $payload);
    });
});
