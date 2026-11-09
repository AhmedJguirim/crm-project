<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\PossibleDuplicateContacts;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contactNamed = function (string $name, ?string $email, ?string $phone, array $companies = [], array $attributes = []): Contact {
        $contact = Contact::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            ...$attributes,
        ]);
        $contact->companies()->attach(collect($companies)->map->id->all());

        return $contact;
    };
    $this->companyNamed = fn (string $name, ?int $organizationId = null): Company => Company::factory()->create([
        'organization_id' => $organizationId ?? $this->org->id,
        'name' => $name,
    ]);
    $this->field = fn ($page, string $name) => $page->instance()->getSchema('form')->getFlatFields()[$name];
    $this->phoneHint = fn ($page): ?string => ($this->field)($page, 'phone')->getHint();
    $this->nameHint = fn ($page): ?string => ($this->field)($page, 'name')->getHint();
});

it('flags the same phone in another format and still saves', function () {
    ($this->contactNamed)('Marcus Chen', 'marcus.chen@techcorp.io', '+33 6 12 34 56 78');

    $page = Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Chloé Martin', 'phone' => '+33 (6) 12-34-56-78']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Marcus Chen (marcus.chen@techcorp.io)');

    $phone = ($this->field)($page, 'phone');
    expect($phone->getHintIcon())->toBe(Heroicon::ExclamationTriangle)
        ->and($phone->getHintIconTooltip())->toBe('Another contact in this organization has the same phone number. This is only a warning: you can still save.')
        ->and($phone->getHintColor())->toBe('warning');

    $page->call('create')->assertHasNoFormErrors();

    expect(Contact::query()->where('phone', 'like', '%12%')->count())->toBe(2)
        ->and(Contact::query()->where('name', 'Chloé Martin')->exists())->toBeTrue();
});

it('lists an email-less contact by name only', function () {
    ($this->contactNamed)('Jane Doe', null, '06 12 34 56 78');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '0612345678']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Jane Doe');
});

it('gives no phone hint when the phone cannot be compared or differs', function (?string $phone) {
    ($this->contactNamed)('Jane Doe', null, '06 12 34 56 78');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => $phone]);

    expect(($this->phoneHint)($page))->toBeNull()
        ->and(($this->field)($page, 'phone')->getHintIcon())->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
    'fewer than 6 digits' => ['12-34'],
    'five digits' => ['12345'],
    'other digits (no country logic)' => ['+33 6 12 34 56 78'],
    'different number' => ['06 12 34 56 79'],
]);

it('flags a phone of exactly six digits', function () {
    ($this->contactNamed)('Jane Doe', null, '12 34 56');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '123456']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Jane Doe');
});

it('lists at most three names ordered by name', function () {
    foreach (['E', 'D', 'C', 'B', 'A'] as $name) {
        ($this->contactNamed)($name, null, '0611111111');
    }

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '06 11 11 11 11']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: A, B, C and 2 more');
});

it('orders same-name matches by id', function () {
    $first = ($this->contactNamed)('Twin', 'b@example.com', '0611111111');
    ($this->contactNamed)('Twin', 'a@example.com', '0611111111');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '0611111111']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Twin (b@example.com), Twin (a@example.com)')
        ->and($first->id)->toBeLessThan(Contact::query()->where('email', 'a@example.com')->value('id'));
});

it('flags the same name and a common company', function () {
    $acme = ($this->companyNamed)('Acme');
    $globex = ($this->companyNamed)('Globex');
    ($this->contactNamed)('Jane Doe', 'jane@acme.com', null, [$acme]);

    $page = Livewire::test(CreateContact::class)
        ->fillForm(['name' => '  jane DOE ', 'companies' => [$globex->id, $acme->id]]);

    $name = ($this->field)($page, 'name');
    expect($name->getHint())->toBe('Same name and company as: Jane Doe (jane@acme.com)')
        ->and($name->getHintIcon())->toBe(Heroicon::ExclamationTriangle)
        ->and($name->getHintColor())->toBe('warning')
        ->and($name->getHintIconTooltip())->toBe('Another contact in this organization has the same name and works for one of the same companies. This is only a warning: you can still save.');

    $page->call('create')->assertHasNoFormErrors();

    expect(Contact::query()->whereRaw("lower(trim(name)) = 'jane doe'")->count())->toBe(2);
});

it('gives no name hint without the same name and a common company', function (string $name, array $companies) {
    $acme = ($this->companyNamed)('Acme');
    $globex = ($this->companyNamed)('Globex');
    ($this->contactNamed)('Jane Doe', null, null, [$acme]);
    $ids = ['Acme' => $acme->id, 'Globex' => $globex->id];

    $page = Livewire::test(CreateContact::class)
        ->fillForm(['name' => $name, 'companies' => array_map(fn (string $company): int|string => $ids[$company] ?? $company, $companies)]);

    expect(($this->nameHint)($page))->toBeNull()
        ->and(($this->field)($page, 'name')->getHintIcon())->toBeNull();
})->with([
    'no company' => ['Jane Doe', []],
    'other company' => ['Jane Doe', ['Globex']],
    'other name' => ['John Doe', ['Acme']],
    'blank name' => ['   ', ['Acme']],
]);

it('ignores junk company ids', function (mixed $ids) {
    $acme = ($this->companyNamed)('Acme');
    ($this->contactNamed)('Jane Doe', null, null, [$acme]);

    expect(PossibleDuplicateContacts::withSameNameAndCompany('Jane Doe', $ids, null))->toBeNull();
})->with([
    'text and padded' => [['abc', ' 5', '5 ', '-1', '']],
    'nested arrays' => [[[1], ['x']]],
    'floats and nulls' => [[1.5, null, true]],
]);

it('accepts a numeric string company id next to junk', function () {
    $acme = ($this->companyNamed)('Acme');
    $jane = ($this->contactNamed)('Jane Doe', null, null, [$acme]);

    $matches = PossibleDuplicateContacts::withSameNameAndCompany('Jane Doe', ['abc', (string) $acme->id], null);

    expect($matches->pluck('id')->all())->toBe([$jane->id]);
});

it('ignores the record itself, trashed contacts, trashed companies and other organizations', function () {
    $acme = ($this->companyNamed)('Acme');
    $mine = ($this->contactNamed)('Jane Doe', 'jane@acme.com', '0612345678', [$acme]);
    ($this->contactNamed)('Jane Doe', 'trashed@acme.com', '0612345678', [$acme])->delete();

    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $other->personalOrganization();
    $otherCompany = ($this->companyNamed)('Acme', $otherOrg->id);
    Contact::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Jane Doe', 'email' => 'x@other.test', 'phone' => '0612345678'])
        ->companies()->attach($otherCompany->id);

    $page = Livewire::test(EditContact::class, ['record' => $mine->getRouteKey()]);

    expect(($this->phoneHint)($page))->toBeNull()
        ->and(($this->nameHint)($page))->toBeNull();

    $create = Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Doe', 'phone' => '0612345678', 'companies' => [$otherCompany->id]])
        ->assertSuccessful();

    expect(($this->nameHint)($create))->toBeNull();
});

it('ignores a trashed company', function () {
    $acme = ($this->companyNamed)('Acme');
    ($this->contactNamed)('Jane Doe', null, null, [$acme]);
    $acme->delete();

    $page = Livewire::test(CreateContact::class)->fillForm(['name' => 'Jane Doe', 'companies' => [$acme->id]]);

    expect(($this->nameHint)($page))->toBeNull();
});

it('flags the other contact on the edit form', function () {
    $marcus = ($this->contactNamed)('Marcus Chen', 'marcus.chen@techcorp.io', '+33 6 12 34 56 78');
    ($this->contactNamed)('Chloé Martin', 'chloe.martin@lumiere-digital.fr', '+33 6 12 34 56 78');

    $page = Livewire::test(EditContact::class, ['record' => $marcus->getRouteKey()]);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Chloé Martin (chloe.martin@lumiere-digital.fr)');
});

it('never gives a 500 for forged state', function () {
    expect(PossibleDuplicateContacts::withSamePhone(['0612345678'], null))->toBeNull()
        ->and(PossibleDuplicateContacts::withSameNameAndCompany('Jane Doe', '5', null))->toBeNull()
        ->and(PossibleDuplicateContacts::withSameNameAndCompany(['Jane Doe'], [5], null))->toBeNull()
        ->and(PossibleDuplicateContacts::phoneDigits(['x']))->toBeNull()
        ->and(PossibleDuplicateContacts::phoneDigits(null))->toBeNull();
});

it('keeps only ASCII digits', function () {
    expect(PossibleDuplicateContacts::phoneDigits('+33 (6) 12-34-56-78'))->toBe('33612345678')
        ->and(PossibleDuplicateContacts::phoneDigits('٠٦١٢٣٤٥٦٧٨'))->toBeNull();
});

it('does not compare a five-digit phone even when another contact has the same five digits', function () {
    ($this->contactNamed)('Short', null, '12345');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '12345']);

    expect(($this->phoneHint)($page))->toBeNull();
});

it('drops letters from the typed phone before comparing', function () {
    ($this->contactNamed)('Jane Doe', null, '0612345678');

    $page = Livewire::test(CreateContact::class)->fillForm(['phone' => '06-12-34-56-78 ab']);

    expect(($this->phoneHint)($page))->toBe('Same phone as: Jane Doe')
        ->and(PossibleDuplicateContacts::phoneDigits('ab 06 12 34 56 78 x'))->toBe('0612345678');
});

it('matches a stored name with outer spaces', function () {
    $acme = ($this->companyNamed)('Acme');
    ($this->contactNamed)('  Jane Doe  ', null, null, [$acme]);

    $page = Livewire::test(CreateContact::class)->fillForm(['name' => 'jane doe', 'companies' => [$acme->id]]);

    expect(($this->nameHint)($page))->toBe('Same name and company as:   Jane Doe  ');
});
