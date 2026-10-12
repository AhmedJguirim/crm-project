<?php

use App\Enums\CompanyIndustry;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\User;
use App\Services\CompanyImportService;
use App\Support\CsvDialect;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->sme = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $this->vat = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'VAT Number', 'type' => 'text', 'unique' => true, 'order' => 1]);
    $this->initech = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech', 'website' => 'initech.com']);

    $this->service = new CompanyImportService($this->org->id);
    $this->importRow = fn (array $row, ?CompanyImportService $service = null): array => ($service ?? $this->service)->processRow($row);
    $this->companyNamed = fn (string $name): ?Company => Company::forOrganization($this->org->id)->where('name', $name)->first();
});

it('imports new companies and refuses the one that already exists', function () {
    $nova = ($this->importRow)(['name' => 'Nova', 'website' => 'nova.io', 'type' => 'sme', 'industry' => 'software', 'employees' => '85']);
    $existing = ($this->importRow)(['name' => 'Initech', 'website' => 'initech.com']);
    $globex = ($this->importRow)(['name' => 'Globex', 'type' => 'SME', 'industry' => 'Finance & Banking']);

    $nova = ($this->companyNamed)('Nova');
    $globex = ($this->companyNamed)('Globex');

    expect($existing)->toBe(['success' => false, 'error' => 'A company named Initech (initech.com) already exists.'])
        ->and($nova->company_type_id)->toBe($this->sme->id)
        ->and($nova->industry)->toBe(CompanyIndustry::Software)
        ->and($nova->employees)->toBe(85)
        ->and($nova->domain)->toBe('nova.io')
        ->and($globex->industry)->toBe(CompanyIndustry::FinanceBanking)
        ->and($globex->website)->toBeNull()
        ->and(Company::forOrganization($this->org->id)->count())->toBe(3);
});

it('names an existing company without a website without a domain', function () {
    Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Plain Co', 'website' => null]);

    expect(($this->importRow)(['name' => ' plain co ']))->toBe(['success' => false, 'error' => 'A company named Plain Co already exists.']);
});

it('refuses a company in the trash and a name shared by several companies', function () {
    Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old Co', 'website' => null])->delete();
    Company::factory()->count(2)->create(['organization_id' => $this->org->id, 'name' => 'Twin', 'website' => null]);

    expect(($this->importRow)(['name' => 'Old Co'])['error'])->toBe('The company Old Co is in the trash. Restore it first.')
        ->and(($this->importRow)(['name' => 'Twin'])['error'])->toBe('Several companies are named Twin. Add the company website to choose.');
});

it('refuses an invalid value and creates nothing', function (string $column, string $value) {
    $result = ($this->importRow)(['name' => 'Nova', $column => $value]);

    expect($result)->toBe(['success' => false, 'error' => "Invalid value for field '{$column}': {$value}"])
        ->and(($this->companyNamed)('Nova'))->toBeNull()
        ->and(Address::query()->count())->toBe(0);
})->with([
    'unknown type' => ['type', 'Giant'],
    'unknown industry' => ['industry', 'Mining'],
    'negative employees' => ['employees', '-3'],
    'revenue not a number' => ['annual revenue', 'lots'],
    'website without a host' => ['website', 'not a website'],
    'phone too long' => ['phone', str_repeat('1', 51)],
    'city too long' => ['city', str_repeat('a', 256)],
]);

it('does not create the address of a row that fails', function () {
    $result = ($this->importRow)(['name' => 'Nova', 'city' => 'Lyon', 'industry' => 'Mining']);

    expect($result['success'])->toBeFalse()
        ->and(Address::query()->count())->toBe(0);
});

it('requires a name', function () {
    expect(($this->importRow)(['name' => '  ', 'website' => 'nova.io']))->toBe(['success' => false, 'error' => 'Name is required.'])
        ->and(($this->importRow)(['website' => 'nova.io'])['error'])->toBe('Name is required.');
});

it('refuses a name or website longer than the column', function (string $column) {
    $value = str_repeat('a', 256);

    expect(($this->importRow)(['name' => $column === 'name' ? $value : 'Nova', 'website' => $column === 'website' ? "{$value}.com" : ''])['error'])
        ->toStartWith("Invalid value for field '{$column}': ");
})->with(['name', 'website']);

it('fills custom fields by their plain name and keeps them unique', function () {
    $nova = ($this->importRow)(['name' => 'Nova', 'VAT Number' => 'FR1']);
    $zeta = ($this->importRow)(['name' => 'Zeta', 'VAT Number' => 'FR1']);

    expect($nova['success'])->toBeTrue()
        ->and($zeta)->toBe(['success' => false, 'error' => "Duplicate value for unique field 'VAT Number'."])
        ->and(($this->companyNamed)('Nova')->customFieldValue($this->vat->key))->toBe('FR1')
        ->and(($this->companyNamed)('Zeta'))->toBeNull();
});

it('refuses an invalid custom field value', function () {
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Founded', 'type' => 'date', 'unique' => false, 'order' => 2]);

    expect((new CompanyImportService($this->org->id))->processRow(['name' => 'Nova', 'Founded' => '31-02-2026']))
        ->toBe(['success' => false, 'error' => "Invalid value for field 'Founded': 31-02-2026"]);
});

it('reports a unique value taken between the check and the save as a failed row', function () {
    insertCompetingRowAfterUniquenessCheck('companies', storedRowOf(Company::factory()->make([
        'organization_id' => $this->org->id,
        'name' => 'Racer',
        'custom_field_values' => [$this->vat->key => 'FR9'],
    ])), 'FR9');

    $result = ($this->importRow)(['name' => 'Nova', 'VAT Number' => 'FR9']);

    expect($result)->toBe(['success' => false, 'error' => "Duplicate value for unique field 'VAT Number'."])
        ->and(($this->companyNamed)('Nova'))->toBeNull();
});

it('refuses the same company twice in one file, by name or by domain', function () {
    $first = ($this->importRow)(['name' => 'Nova', 'website' => 'nova.io']);
    $second = ($this->importRow)(['name' => 'NOVA', 'website' => 'https://www.nova.io']);
    $byName = ($this->importRow)(['name' => 'Solo']);
    $byNameAgain = ($this->importRow)(['name' => 'solo']);

    expect($first['success'])->toBeTrue()
        ->and($second['error'])->toBe('A company named Nova (nova.io) already exists.')
        ->and($byName['success'])->toBeTrue()
        ->and($byNameAgain['error'])->toBe('A company named Solo already exists.')
        ->and(Company::forOrganization($this->org->id)->whereIn('name', ['Nova', 'NOVA', 'Solo', 'solo'])->count())->toBe(2);
});

it('creates the address when any part is filled', function () {
    ($this->importRow)(['name' => 'Nova', 'city' => 'Lyon', 'country' => 'France']);
    ($this->importRow)(['name' => 'Zeta']);

    $address = ($this->companyNamed)('Nova')->address;

    expect($address->city)->toBe('Lyon')
        ->and($address->country)->toBe('France')
        ->and(($this->companyNamed)('Zeta')->address_id)->toBeNull();
});

it('stores the name and website trimmed, and the notes as typed', function () {
    ($this->importRow)(['name' => '  Nova  ', 'website' => '  HTTPS://Nova.io ', 'notes' => "  Key account\nVIP "]);

    $nova = ($this->companyNamed)('Nova');

    expect($nova->website)->toBe('HTTPS://Nova.io')
        ->and($nova->domain)->toBe('nova.io')
        ->and($nova->notes)->toBe("  Key account\nVIP ");
});

it('reads European numbers only in a ; file', function () {
    $european = new CompanyImportService($this->org->id, new CsvDialect(';'));

    expect(($this->importRow)(['name' => 'Nova', 'annual revenue' => '1250,5'], $european)['success'])->toBeTrue()
        ->and((string) ($this->companyNamed)('Nova')->annual_revenue)->toBe('1250.50')
        ->and(($this->importRow)(['name' => 'Zeta', 'annual revenue' => '1250,5'])['success'])->toBeFalse();
});

it('does not touch the companies of another organization', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    Company::factory()->create(['organization_id' => $other->id, 'name' => 'Nova', 'website' => 'nova.io']);

    expect(($this->importRow)(['name' => 'Nova', 'website' => 'nova.io'])['success'])->toBeTrue()
        ->and(Company::forOrganization($other->id)->count())->toBe(1);
});

describe('headers', function () {
    it('matches the columns ignoring case and spaces, and the custom fields by name', function () {
        expect($this->service->canonicalHeaders([' Name ', 'WEBSITE', 'Annual Revenue', ' vat number', 'Shoe size', '_ROW_NUMBER']))
            ->toBe(['name', 'website', 'annual revenue', 'VAT Number', 'Shoe size', '_row_number']);
    });

    it('matches the id column ignoring case and spaces, and does not list it as ignored', function () {
        expect($this->service->canonicalHeaders(['ID ', 'Name']))->toBe(['id', 'name'])
            ->and($this->service->ignoredColumns(['ID ', 'name']))->toBe([]);
    });

    it('lists the headers no column uses, naming the ones of a deleted field', function () {
        CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old', 'type' => 'text', 'order' => 2])->delete();
        $service = new CompanyImportService($this->org->id);

        expect($service->ignoredColumns(['name', ' Shoe size', 'old', 'company', '_error', '']))
            ->toBe(['" Shoe size"', '"old" (deleted field)', '"company"']);
    });

    it('finds two headers for one column, but not the ones of the failed rows file', function () {
        expect($this->service->duplicatedColumn(['Name', ' name ', 'website']))->toBe('name')
            ->and($this->service->duplicatedColumn(['_error', '_error', '_row_number', 'name']))->toBeNull()
            ->and($this->service->duplicatedColumn(['name', 'website', 'VAT Number']))->toBeNull();
    });
});
