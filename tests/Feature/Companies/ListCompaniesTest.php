<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('index page renders', function () {
    $this->get(CompanyResource::getUrl('index'))->assertOk();
});

test('lists companies of the current organization only', function () {
    $companies = Company::factory()->count(3)->create(['organization_id' => $this->org->id]);
    $foreign = Company::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords($companies)
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertCountTableRecords(3);
});

test('shows type, contacts count and location columns', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Enterprise']);
    $company = Company::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $type->id,
        'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'city' => 'Paris', 'country' => 'France'])->id,
    ]);
    $company->contacts()->attach(Contact::factory()->count(2)->create(['organization_id' => $this->org->id]));

    $page = Livewire::test(ListCompanies::class)
        ->assertTableColumnStateSet('companyType.name', 'Enterprise', $company)
        ->assertTableColumnStateSet('address.city', 'Paris', $company)
        ->assertTableColumnStateSet('address.country', 'France', $company);

    expect($page->instance()->getTableRecords()->firstWhere('id', $company->id)->contacts_count)->toBe(2);
});

test('can search companies by name and city', function () {
    $acme = Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Acme Corp',
        'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'city' => 'Lisbon'])->id,
    ]);
    $globex = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Globex']);

    Livewire::test(ListCompanies::class)
        ->searchTable('Acme')
        ->assertCanSeeTableRecords([$acme])
        ->assertCanNotSeeTableRecords([$globex])
        ->searchTable('Lisbon')
        ->assertCanSeeTableRecords([$acme])
        ->assertCanNotSeeTableRecords([$globex]);
});

test('can sort companies by name', function () {
    $companies = collect(['Charlie', 'Alpha', 'Bravo'])
        ->map(fn (string $name) => Company::factory()->create(['organization_id' => $this->org->id, 'name' => $name]));

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords($companies->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($companies->sortByDesc('name'), inOrder: true);
});

test('can filter companies by type', function () {
    $enterprise = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $startup = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $big = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $enterprise->id]);
    $small = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $startup->id]);

    Livewire::test(ListCompanies::class)
        ->filterTable('company_type_id', $enterprise->id)
        ->assertCanSeeTableRecords([$big])
        ->assertCanNotSeeTableRecords([$small]);
});

test('trashed companies are hidden unless the trashed filter is used', function () {
    $active = Company::factory()->create(['organization_id' => $this->org->id]);
    $trashed = Company::factory()->create(['organization_id' => $this->org->id]);
    $trashed->delete();

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$active]);
});

test('can bulk delete and restore companies', function () {
    $companies = Company::factory()->count(2)->create(['organization_id' => $this->org->id]);

    Livewire::test(ListCompanies::class)
        ->selectTableRecords($companies)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    $companies->each(fn (Company $company) => $this->assertSoftDeleted($company));

    Livewire::test(ListCompanies::class)
        ->filterTable('trashed', false)
        ->selectTableRecords($companies)
        ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

    $companies->each(fn (Company $company) => $this->assertNotSoftDeleted($company));
});

test('companies are globally searchable', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Startup']);
    Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Unique Searchable Company', 'company_type_id' => $type->id]);
    Company::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Unique Searchable Company']);

    $results = CompanyResource::getGlobalSearchResults('Unique Searchable');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Unique Searchable Company')
        ->and($results->first()->details)->toBe(['Type' => 'Startup']);
});
