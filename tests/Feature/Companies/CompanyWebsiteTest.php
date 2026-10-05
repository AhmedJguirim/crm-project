<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
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
    $this->hintOf = fn ($page): ?string => $page->instance()->getSchema('form')->getFlatFields()['website']->getHint();
});

it('derives the domain from the website', function (?string $website, ?string $domain) {
    expect(Company::domainFrom($website))->toBe($domain);
})->with([
    ['https://www.Acme.com/about', 'acme.com'],
    ['acme.com', 'acme.com'],
    ['http://acme.com:8080', 'acme.com'],
    ['WWW.ACME.CO.UK', 'acme.co.uk'],
    ['https://shop.acme.com', 'shop.acme.com'],
    ['  acme.com', 'acme.com'],
    ['', null],
    [null, null],
    ['not a website', null],
    ['localhost', null],
    ['https://localhost:8000', null],
    ['acme', null],
    ['HTTP://Acme.com', 'acme.com'],
    ['acme.com/?next=http://other.org', 'acme.com'],
    ['javascript://acme.com/%0aalert(1)', null],
    ['JaVaScRiPt://acme.com', null],
    ['data://acme.com/x', null],
    ['ftp://acme.com', null],
    ['javascript:alert(1)', null],
]);

it('keeps the domain in step with the website', function () {
    $company = ($this->company)('Acme', 'acme.com');

    expect($company->fresh()->domain)->toBe('acme.com');

    $company->update(['website' => 'https://www.globex.com']);
    expect($company->fresh()->domain)->toBe('globex.com');

    $company->update(['notes' => 'unrelated']);
    expect($company->fresh()->domain)->toBe('globex.com');

    $company->update(['website' => null]);
    expect($company->fresh()->domain)->toBeNull();
});

it('does not let the domain be mass-assigned', function () {
    $company = Company::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'website' => 'acme.com', 'domain' => 'evil.com']);

    expect($company->fresh()->domain)->toBe('acme.com');

    $company->update(['domain' => 'evil.com', 'notes' => 'changed']);

    expect($company->fresh()->domain)->toBe('acme.com');
});

it('stores the website as typed, trimmed only, and the link adds https', function () {
    $company = ($this->company)('Acme', ' acme.com ');
    $secure = ($this->company)('Secure', 'http://secure.test');

    expect($company->fresh()->website)->toBe('acme.com')
        ->and($company->websiteUrl())->toBe('https://acme.com')
        ->and($secure->websiteUrl())->toBe('http://secure.test')
        ->and(($this->company)('None')->websiteUrl())->toBeNull();
});

it('only links http and https websites, whatever is stored', function (string $website, ?string $url) {
    $company = Company::factory()->make(['organization_id' => $this->org->id]);
    $company->forceFill(['website' => $website]);

    expect($company->websiteUrl())->toBe($url);
})->with([
    ['acme.com', 'https://acme.com'],
    ['HTTP://Acme.com', 'HTTP://Acme.com'],
    ['https://acme.com/a', 'https://acme.com/a'],
    ['acme.com/?next=http://other.org', 'https://acme.com/?next=http://other.org'],
    ['javascript://acme.com/%0aalert(document.domain)', null],
    ['JaVaScRiPt://acme.com', null],
    ['data://acme.com/x', null],
    ['ftp://acme.com', null],
    ["\x01javascript://acme.com", 'https://'."\x01javascript://acme.com"],
]);

describe('the form', function () {
    it('stores the website as typed, its domain and the phone', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Acme', 'website' => 'acme.com', 'phone' => '+33 1 23 45 67 89'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Company::query()->where('name', 'Acme')->sole()->only(['website', 'domain', 'phone']))
            ->toBe(['website' => 'acme.com', 'domain' => 'acme.com', 'phone' => '+33 1 23 45 67 89']);
    });

    it('refuses a website that has no domain', function (string $website) {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Acme', 'website' => $website])
            ->call('create')
            ->assertHasFormErrors(['website']);

        expect(Company::count())->toBe(0);
    })->with(['not a website', 'localhost', 'javascript://acme.com/%0aalert(1)', 'data://acme.com/x', 'ftp://acme.com']);

    it('shows the message of an invalid website', function (string $website) {
        $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'Acme', 'website' => $website])->call('create');

        expect($page->errors()->get('data.website'))->toBe(['Enter a website like acme.com or https://acme.com.']);
    })->with(['not a website', 'javascript://acme.com/%0aalert(1)']);

    it('accepts an uppercase http scheme', function () {
        Livewire::test(CreateCompany::class)->fillForm(['name' => 'Acme', 'website' => 'HTTP://Acme.com'])->call('create')->assertHasNoFormErrors();

        expect(Company::query()->where('name', 'Acme')->sole()->domain)->toBe('acme.com');
    });

    it('limits the phone to 50 characters', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Acme', 'phone' => str_repeat('1', 51)])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    });

    it('flags a shared domain without refusing it', function () {
        ($this->company)('Acme France', 'https://acme.com');

        $page = Livewire::test(CreateCompany::class)->fillForm(['name' => 'Acme Spain', 'website' => 'www.acme.com/fr']);

        expect(($this->hintOf)($page))->toBe('Also used by: Acme France');

        $page->call('create')->assertHasNoFormErrors();

        expect(Company::query()->where('domain', 'acme.com')->count())->toBe(2);
    });

    it('does not flag the company itself or a trashed company', function () {
        $acme = ($this->company)('Acme', 'acme.com');
        ($this->company)('Old Acme', 'acme.com')->delete();

        $page = Livewire::test(EditCompany::class, ['record' => $acme->id]);

        expect(($this->hintOf)($page))->toBeNull();
    });

    it('lists at most three names', function () {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            ($this->company)($name, 'acme.com');
        }

        $page = Livewire::test(CreateCompany::class)->fillForm(['website' => 'acme.com']);

        expect(($this->hintOf)($page))->toBe('Also used by: A, B, C and 2 more');
    });

    it('stays inside the organization', function () {
        ($this->company)('Foreign Acme', 'acme.com', Organization::factory()->create());

        $page = Livewire::test(CreateCompany::class)->fillForm(['website' => 'acme.com']);

        expect(($this->hintOf)($page))->toBeNull();
    });

    it('has no hint without a usable website', function () {
        ($this->company)('Acme', 'acme.com');

        expect(($this->hintOf)(Livewire::test(CreateCompany::class)))->toBeNull()
            ->and(($this->hintOf)(Livewire::test(CreateCompany::class)->fillForm(['website' => 'nonsense'])))->toBeNull();
    });
});

describe('the table', function () {
    it('shows the domain as a link, finds companies by it, and hides the phone by default', function () {
        $acme = ($this->company)('Acme', 'https://www.acme.com');
        $other = ($this->company)('Other', 'other.org');

        $page = Livewire::test(ListCompanies::class)
            ->assertTableColumnStateSet('domain', 'acme.com', $acme)
            ->searchTable('other.org')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$acme]);

        $table = $page->instance()->getTable();

        expect($table->getColumn('domain')->record($acme)->getUrl())->toBe('https://www.acme.com')
            ->and($table->getColumn('domain')->record($other)->getUrl())->toBe('https://other.org')
            ->and($table->getColumn('domain')->shouldOpenUrlInNewTab())->toBeTrue()
            ->and($table->getColumn('domain')->getLabel())->toBe('Website')
            ->and($table->getColumn('phone')->isToggledHiddenByDefault())->toBeTrue();
    });
});

describe('a stored dangerous website', function () {
    it('is never rendered as a link in the table', function () {
        $company = ($this->company)('Evil');
        Company::query()->whereKey($company->id)->toBase()->update(['website' => 'javascript://acme.com/%0aalert(document.domain)', 'domain' => 'acme.com']);

        $page = Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$company->fresh()]);

        expect($page->html())->not->toContain('href="javascript')
            ->and($page->html())->not->toContain('javascript://');

        $column = $page->instance()->getTable()->getColumn('domain')->record($company->fresh());

        expect($column->getUrl())->toBeNull();
    });

    it('still renders a safe website as a link', function () {
        $company = ($this->company)('Good', 'https://www.acme.com');

        $page = Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$company]);

        expect($page->html())->toContain('href="https://www.acme.com"');
    });
});

describe('the global search', function () {
    it('finds companies by domain and shows it', function () {
        $acme = ($this->company)('Acme', 'https://www.acme.com');

        expect(CompanyResource::getGloballySearchableAttributes())->toBe(['name', 'domain'])
            ->and(CompanyResource::getGlobalSearchResultDetails($acme->load('companyType', 'address')))->toBe(['Website' => 'acme.com'])
            ->and(CompanyResource::getGlobalSearchEloquentQuery()->where('domain', 'acme.com')->pluck('name')->all())->toBe(['Acme']);
    });
});
