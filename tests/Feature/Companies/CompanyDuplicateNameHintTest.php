<?php

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use App\Services\Companies\PossibleDuplicateCompanies;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->company = fn (string $name, ?string $website = null, ?Organization $organization = null): Company => Company::factory()->create([
        'organization_id' => ($organization ?? $this->org)->id,
        'name' => $name,
        'website' => $website,
    ]);
    $this->nameField = fn ($page) => $page->instance()->getSchema('form')->getFlatFields()['name'];
    $this->nameHint = fn ($page): ?string => ($this->nameField)($page)->getHint();

    $this->acme = ($this->company)('Acme Corp', 'acme.com');
});

it('normalises a typed name', function (mixed $typed, ?string $expected) {
    expect(PossibleDuplicateCompanies::normalizedName($typed))->toBe($expected);
})->with([
    [" \tAcme   Corp  ", 'acme corp'],
    ['ÉLAN Studio', 'élan studio'],
    ['   ', null],
    ['', null],
    [null, null],
    [['Acme'], null],
    [12, null],
]);

it('flags a name equal after normalising, and still saves', function (string $typed) {
    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => $typed]);
    $field = ($this->nameField)($page);

    expect($field->getHint())->toBe('Same name as: Acme Corp (acme.com)')
        ->and($field->getHintColor())->toBe('warning')
        ->and($field->getHintIcon())->toBe(Heroicon::ExclamationTriangle)
        ->and($field->getHintIconTooltip())->toBe('Another company in this organization has the same name. This is only a warning: you can still save.');

    $page->call('create')->assertHasNoFormErrors();

    expect(Company::query()->count())->toBe(2);
})->with([
    'same' => 'Acme Corp',
    'lower case' => 'acme corp',
    'upper case' => 'ACME CORP',
    'outer and double inner spaces' => ' acme  corp ',
    'tab' => "Acme\tCorp",
]);

it('lists a company without a domain by its name only', function () {
    ($this->company)('Globex');

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'globex']);

    expect(($this->nameHint)($page))->toBe('Same name as: Globex');
});

it('shows no hint for a blank or different name', function (?string $typed) {
    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => $typed]);

    expect(($this->nameHint)($page))->toBeNull()
        ->and(($this->nameField)($page)->getHintIcon())->toBeNull();
})->with([
    'empty' => '',
    'null' => null,
    'blanks' => '   ',
    'longer name' => 'Acme Corporation',
    'legal suffix' => 'Acme Corp SARL',
    'spaces removed' => 'AcmeCorp',
]);

it('does not throw on a forged array name', function () {
    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => ['x']]);

    expect(($this->nameHint)($page))->toBeNull();
});

it('does not let the edited company flag itself', function () {
    $page = Livewire::test(EditCompany::class, ['record' => $this->acme->getRouteKey()]);

    expect(($this->nameHint)($page))->toBeNull();
});

it('flags another company with the same name on the edit page', function () {
    ($this->company)('acme corp');

    $page = Livewire::test(EditCompany::class, ['record' => $this->acme->getRouteKey()]);

    expect(($this->nameHint)($page))->toBe('Same name as: acme corp');
});

it('compares a stored name with irregular spacing', function () {
    ($this->company)("Globex \t  Inc ");

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'GLOBEX inc']);

    expect(($this->nameHint)($page))->toBe("Same name as: Globex \t  Inc ");
});

it('ignores a trashed company', function () {
    ($this->company)('Old Acme')->delete();

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'old acme']);

    expect(($this->nameHint)($page))->toBeNull();
});

it('does not see the company of another organization', function () {
    $other = Organization::factory()->create();
    ($this->company)('Initech', null, $other);

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'Initech']);

    expect(($this->nameHint)($page))->toBeNull();
});

it('lists at most three names ordered by name then id', function () {
    foreach (range(1, 4) as $ignored) {
        ($this->company)('Acme Corp');
    }
    $this->acme->update(['notes' => 'moved in the table']);

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'acme corp']);

    expect(($this->nameHint)($page))->toBe('Same name as: Acme Corp (acme.com), Acme Corp, Acme Corp and 2 more');
});

it('says "and 1 more" at four matches', function () {
    foreach (range(1, 3) as $ignored) {
        ($this->company)('Acme Corp');
    }

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'acme corp']);

    expect(($this->nameHint)($page))->toBe('Same name as: Acme Corp (acme.com), Acme Corp, Acme Corp and 1 more');
});

it('never writes while building the hint', function () {
    $before = Company::query()->count();

    $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'acme corp']);
    ($this->nameHint)($page);

    expect(Company::query()->count())->toBe($before);
});
