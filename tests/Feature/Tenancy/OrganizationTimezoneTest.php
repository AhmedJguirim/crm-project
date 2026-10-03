<?php

use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

describe('the model', function () {
    it('defaults to UTC', function () {
        expect(Organization::factory()->create()->fresh()->timezone)->toBe('UTC')
            ->and($this->org->fresh()->timezone)->toBe('UTC');
    });

    it('gives the current time in its timezone', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(23, 30));
        $organization = Organization::factory()->timezone('Europe/Paris')->create();

        expect($organization->localNow()->toDateTimeString())->toBe('2026-03-16 00:30:00')
            ->and($organization->localNow()->timezoneName)->toBe('Europe/Paris');
    });

    it('keeps a valid timezone and falls back to UTC for anything else', function (?string $given, string $expected) {
        expect(Organization::validTimezoneOrUtc($given))->toBe($expected);
    })->with([
        'valid' => ['Europe/Paris', 'Europe/Paris'],
        'invalid' => ['Not/AZone', 'UTC'],
        'empty' => ['', 'UTC'],
        'missing' => [null, 'UTC'],
        'lowercase' => ['europe/paris', 'UTC'],
    ]);
});

describe('the settings page', function () {
    it('shows the current timezone', function () {
        Livewire::test(EditOrganization::class)->assertFormSet(['timezone' => 'UTC']);
    });

    it('lets the timezone be changed', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['timezone' => 'Europe/Paris'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->timezone)->toBe('Europe/Paris');
    });

    it('refuses an unknown timezone', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['timezone' => 'Mars/Olympus'])
            ->call('save')
            ->assertHasFormErrors(['timezone']);

        expect($this->org->fresh()->timezone)->toBe('UTC');
    });

    it('requires a timezone', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['timezone' => null])
            ->call('save')
            ->assertHasFormErrors(['timezone' => 'required']);
    });

    it('explains what the timezone is for', function () {
        Livewire::test(EditOrganization::class)
            ->assertSee('Used for date-based segments, task reminders and the daily digest.');
    });
});

describe('registering an organization', function () {
    it('stores the chosen timezone', function () {
        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Globex', 'timezone' => 'Asia/Kolkata'])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(Organization::where('name', 'Globex')->value('timezone'))->toBe('Asia/Kolkata');
    });

    it('starts with UTC', function () {
        Livewire::test(RegisterOrganization::class)->assertFormSet(['timezone' => 'UTC']);
    });

    it('takes the timezone of the browser as the default', function () {
        Livewire::test(RegisterOrganization::class)
            ->call('prefillTimezone', 'Europe/Paris')
            ->assertFormSet(['timezone' => 'Europe/Paris']);
    });

    it('ignores a browser timezone that is not known', function () {
        Livewire::test(RegisterOrganization::class)
            ->call('prefillTimezone', 'Not/AZone')
            ->call('prefillTimezone', null)
            ->assertFormSet(['timezone' => 'UTC']);
    });

    it('does not replace a timezone the user already chose', function () {
        Livewire::test(RegisterOrganization::class)
            ->fillForm(['timezone' => 'Asia/Kolkata'])
            ->call('prefillTimezone', 'Europe/Paris')
            ->assertFormSet(['timezone' => 'Asia/Kolkata']);
    });

    it('asks the browser for its timezone when the form is shown', function () {
        Livewire::test(RegisterOrganization::class)
            ->assertSeeHtml('setTimeout(() => $wire.prefillTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone), 1000)');
    });
});

describe('signing up', function () {
    beforeEach(function () {
        auth()->logout();
    });

    it('stores the timezone of the browser on the personal organization', function (?string $sent, string $stored) {
        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Sign Up',
                'email' => 'signup@example.com',
                'password' => 'password',
                'passwordConfirmation' => 'password',
                'timezone' => $sent,
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(User::where('email', 'signup@example.com')->sole()->personalOrganization()->timezone)->toBe($stored);
    })->with([
        'valid' => ['Europe/Paris', 'Europe/Paris'],
        'invalid' => ['Not/AZone', 'UTC'],
        'empty' => ['', 'UTC'],
        'missing' => [null, 'UTC'],
    ]);

    it('has no visible timezone field and asks the browser for its timezone', function () {
        Livewire::test(Register::class)
            ->assertSeeHtml('$el.value = Intl.DateTimeFormat().resolvedOptions().timeZone')
            ->assertDontSee('Timezone');
    });

    it('creates a personal organization in UTC for the other callers', function () {
        $user = User::factory()->create();

        expect($user->createPersonalOrganization()->timezone)->toBe('UTC')
            ->and(User::factory()->create()->createPersonalOrganization('Asia/Kolkata')->timezone)->toBe('Asia/Kolkata');
    });
});
