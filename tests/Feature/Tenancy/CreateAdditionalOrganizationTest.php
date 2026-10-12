<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($this->user);

    Filament::setTenant($this->user->personalOrganization());
});

// TSK-2026-0013 AC-001: Successful creation of new Organization
test('user can create additional organization', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Client XYZ Project',
            'description' => 'A test project for client XYZ.',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $org = Organization::where('name', 'Client XYZ Project')->first();

    expect($org)->not->toBeNull()
        ->and($org->personal_team)->toBeFalse()
        ->and($org->created_by)->toBe($this->user->id)
        ->and($org->description)->toBe('A test project for client XYZ.');

    expect($org->getMemberRole($this->user))->toBe(OrganizationRole::Owner);
});

test('new organization appears in user tenants', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'New Workspace',
        ])
        ->call('register');

    $this->user->refresh();

    expect($this->user->organizations)->toHaveCount(2);
});

// TSK-2026-0013 AC-002: Validation errors on creation
test('organization creation requires name', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Organization::where('personal_team', false)->count())->toBe(0);
});

describe('organization names are unique per user', function () {
    beforeEach(function () {
        $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->user->id]);
        $this->acme->members()->attach($this->user, ['role' => OrganizationRole::Owner->value]);
        $this->globex = Organization::factory()->create(['name' => 'Globex']);
        $this->globex->members()->attach($this->user, ['role' => OrganizationRole::Member->value]);
    });

    it('allows the name of another customer', function () {
        $bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $initech = Organization::factory()->create(['name' => 'Initech']);
        $initech->members()->attach($bob, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($bob);
        Filament::setTenant($bob->personalOrganization());

        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Acme'])
            ->call('register')
            ->assertHasNoFormErrors();

        $created = Organization::where('name', 'Acme')->where('id', '!=', $this->acme->id)->sole();

        expect($created->getMemberRole($bob))->toBe(OrganizationRole::Owner);
    });

    it('refuses a name the user already has', function (string $name) {
        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => $name])
            ->call('register')
            ->assertHasFormErrors(['name' => 'You already belong to an organization with this name.']);

        expect(Organization::where('personal_team', false)->count())->toBe(2);
    })->with([
        'owned' => 'Acme',
        'only a member of it' => 'Globex',
        'case-insensitive' => 'acme',
        'spaces ignored' => ' Acme ',
    ]);

    it('does not count an organization the user only created but left', function () {
        $this->acme->members()->detach($this->user);

        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Acme'])
            ->call('register')
            ->assertHasNoFormErrors();
    });
});

describe('leaving the page without creating an organization', function () {
    beforeEach(function () {
        $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->user->id]);
        $this->acme->members()->attach($this->user, ['role' => OrganizationRole::Owner->value]);
        $this->defaultUrl = fn (): string => Filament::getUrl(Filament::getUserDefaultTenant($this->user));
        $this->openFrom = function (?string $previousUrl) {
            session()->setPreviousUrl($previousUrl ?? '');

            return Livewire::test(RegisterOrganization::class);
        };
        $this->cancelUrl = fn ($page): ?string => $page->instance()->getCancelFormAction()->getUrl();
    });

    it('goes back to the organization the user came from', function () {
        $previous = Filament::getUrl($this->acme).'/contacts';

        $page = ($this->openFrom)($previous)->assertSeeHtml('href="'.Filament::getUrl($this->acme).'"');

        expect(($this->cancelUrl)($page))->toBe(Filament::getUrl($this->acme));
    });

    it('falls back to the default organization', function (Closure $previous) {
        $page = ($this->openFrom)($previous($this))->assertSeeHtml('href="'.($this->defaultUrl)().'"');

        expect(($this->cancelUrl)($page))->toBe(($this->defaultUrl)());
    })->with([
        'no previous page' => [fn () => null],
        'another site' => [fn () => 'https://example.com/somewhere'],
        'an organization the user is not in' => [fn () => Filament::getUrl(Organization::factory()->create())],
        'the new organization page itself' => [fn () => Filament::getTenantRegistrationUrl()],
    ]);

    it('keeps the return url during the requests of the form', function () {
        $page = ($this->openFrom)(Filament::getUrl($this->acme).'/contacts')
            ->fillForm(['name' => str_repeat('a', 300)])
            ->call('register')
            ->assertHasFormErrors(['name']);

        expect(($this->cancelUrl)($page))->toBe(Filament::getUrl($this->acme));
    });

    it('creates nothing', function () {
        ($this->openFrom)(Filament::getUrl($this->acme).'/contacts');

        expect($this->user->organizations()->count())->toBe(2);
    });

    it('is hidden for a user without any organization', function () {
        $loner = User::factory()->onboardingCompleted()->create();
        $this->actingAs($loner);

        $page = ($this->openFrom)(null);

        expect(($this->cancelUrl)($page))->toBeNull()
            ->and($page->instance()->getCancelFormAction()->isVisible())->toBeFalse();
    });

    it('still creates an organization', function () {
        ($this->openFrom)(Filament::getUrl($this->acme).'/contacts')
            ->fillForm(['name' => 'Client XYZ'])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(Organization::where('name', 'Client XYZ')->sole()->getMemberRole($this->user))->toBe(OrganizationRole::Owner);
    });
});

// TSK-2026-0013 AC-003: Optional fields handling
test('organization can be created with only name', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Minimal Org',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $org = Organization::where('name', 'Minimal Org')->first();

    expect($org)->not->toBeNull()
        ->and($org->description)->toBeNull()
        ->and($org->logo_path)->toBeNull();
});

// TSK-2026-0013 AC-004: Immediate reflection in switcher
test('created organization is accessible as tenant', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'Switcher Test Org',
        ])
        ->call('register');

    $org = Organization::where('name', 'Switcher Test Org')->first();

    expect($this->user->canAccessTenant($org))->toBeTrue();

    $this->get(Filament::getUrl($org))
        ->assertOk();
});

// Edge case: very long name
test('organization name is limited to 255 characters', function () {
    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => str_repeat('a', 256),
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'max']);
});
