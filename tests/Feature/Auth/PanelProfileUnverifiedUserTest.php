<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Support\Auth\VerificationMails;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

const PPU_REFUSAL = 'You have asked for 5 verification emails in the last 24 hours. Try again later.';

function ppuAsk(string $email, string $password = 'password')
{
    return Livewire::test(EditProfile::class)
        ->set('data.email', $email)
        ->set('data.currentPassword', $password)
        ->call('save');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:00:00', 'UTC'));
    Notification::fake();
    $this->ann = User::factory()->unverified()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Ann',
        'email' => 'ann@exmple.com',
        'password' => Hash::make('password'),
    ]);
    $this->annOrganization = $this->ann->organizations()->first();
    $this->actingAs($this->ann);
});

test('the profile opens for an unverified user without the sections that need a verified email', function () {
    $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Update your name and email address')
        ->assertSee('Choose which emails you get from the CRM')
        ->assertDontSee('Ensure your account is using a long, random password to stay secure')
        ->assertDontSee('Two-factor authentication (2FA)')
        ->assertDontSee('Delete your account and your personal workspace');
});

test('every other panel page still sends her to the prompt', function () {
    $this->get(ContactResource::getUrl('index', tenant: $this->annOrganization))
        ->assertRedirect(Filament::getPanel('admin')->getEmailVerificationPromptUrl());
});

test('the prompt links to the profile', function () {
    $html = $this->get(Filament::getPanel('admin')->getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertSee('Wrong address?')
        ->assertSee('Change it.')
        ->getContent();

    expect($html)->toMatch('/href="'.preg_quote(Filament::getProfileUrl(), '/').'"[^>]*>\s*<span class="fi-link-label">\s*Change it\./');
});

test('she asks for a new address and the link swaps and verifies it', function () {
    $page = ppuAsk('ann@example.com');

    $page->assertHasNoFormErrors()
        ->assertNotified('Check ann@example.com for a link to confirm your new email address.');

    expect($this->ann->fresh())->email->toBe('ann@exmple.com')->pending_email->toBe('ann@example.com')->email_verified_at->toBeNull()
        ->and(emailChangeUrlsSentTo('ann@example.com'))->toHaveCount(1);

    $url = emailChangeUrlsSentTo('ann@example.com')[0];
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:30:00', 'UTC'));
    session()->forget('filament.claimed_notifications');

    $this->get($url)->assertRedirect(Filament::getProfileUrl());

    $fresh = $this->ann->fresh();
    expect($fresh)->email->toBe('ann@example.com')->pending_email->toBeNull()
        ->and($fresh->email_verified_at->equalTo(CarbonImmutable::parse('2026-11-24 09:30:00', 'UTC')))->toBeTrue();
    Notification::assertNotSentTo($this->ann, AccountSecurityNotification::class);
    Notification::assertNothingSentTo($this->ann);

    $this->get(ContactResource::getUrl('index', tenant: $this->annOrganization))->assertOk();
});

test('the mail cap still applies to an unverified user', function () {
    foreach (range(1, 5) as $ignored) {
        expect(VerificationMails::claim($this->ann))->toBeNull();
    }

    $page = Livewire::test(EditProfile::class)
        ->set('data.name', 'Ann Lee')
        ->set('data.email', 'ann@example.com')
        ->set('data.currentPassword', 'password')
        ->call('save');

    expect($page->errors()->get('data.email'))->toBe([PPU_REFUSAL])
        ->and($this->ann->fresh())->name->toBe('Ann')->pending_email->toBeNull();
    expect(emailChangeUrlsSentTo('ann@example.com'))->toBe([]);
});

test('a pending change shows on the profile of an unverified user', function () {
    ppuAsk('ann@example.com');

    $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Email change pending')
        ->assertSee('Send the link again')
        ->assertDontSee('Delete your account and your personal workspace');
});

test('an unverified user cannot change the address without the right password', function () {
    $page = ppuAsk('ann@example.com', 'wrong-password');

    expect($page->errors()->get('data.currentPassword'))->not->toBe([])
        ->and($this->ann->fresh())->email->toBe('ann@exmple.com')->pending_email->toBeNull();
    expect(emailChangeUrlsSentTo('ann@example.com'))->toBe([]);
});

test('an unverified user forging a hidden action gets a no-op', function (string $action, string $component) {
    Livewire::test(EditProfile::class)
        ->call('mountAction', $action, [], ['schemaComponent' => $component])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction')
        ->assertSet('mountedActions', []);

    expect(User::query()->whereKey($this->ann->getKey())->exists())->toBeTrue()
        ->and(Organization::query()->whereKey($this->annOrganization->getKey())->exists())->toBeTrue()
        ->and(Hash::check('password', $this->ann->fresh()->password))->toBeTrue();
})->with([
    'delete account' => ['deleteAccount', 'content.deleteAccountSection'],
    'change password' => ['changePassword', 'content.passwordSection'],
]);

test('a guest is sent to the panel login', function () {
    auth()->logout();

    $this->get(Filament::getProfileUrl())->assertRedirect(Filament::getLoginUrl());
});

test('a verified user keeps every section', function () {
    $this->ann->forceFill(['email_verified_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC')])->save();

    $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Ensure your account is using a long, random password to stay secure')
        ->assertSee('Two-factor authentication (2FA)')
        ->assertSee('Delete your account and your personal workspace');
});
