<?php

use App\Enums\CompanyIndustry;
use App\Jobs\ProcessContactImportJob;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\User;
use App\Services\ContactImportService;
use App\Services\ContactImportTemplate;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->sme = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $this->vat = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'VAT Number', 'type' => 'text', 'unique' => true, 'order' => 1]);
    $this->founded = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Founded', 'type' => 'date', 'unique' => false, 'order' => 2]);

    $this->importFile = function (string $csv): string {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'];
    };
    $this->importRow = fn (string $header, string $cells): string => ($this->importFile)("name,email,company,{$header}\nAnn,ann@x.test,Nova,{$cells}\n");
    $this->nova = fn (): Company => Company::forOrganization($this->org->id)->where('name', 'Nova')->firstOrFail();
    $this->companyCount = fn (): int => Company::forOrganization($this->org->id)->withTrashed()->count();
    $this->contactCount = fn (): int => Contact::forOrganization($this->org->id)->withTrashed()->count();
    $this->makeCompany = fn (string $name, array $attributes = []): Company => Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        ...$attributes,
    ]);
});

describe('a new company', function () {
    it('gets every value of the row', function () {
        $body = ($this->importFile)(
            "name,email,company,company type,company industry,company employees,company annual revenue,company phone,company city,company country,company: VAT Number,Company:founded\n".
            'Ann,ann@x.test,Nova,sme,software,85,"1250000.50","+33 1 23 45 67 89",Lyon,France,FR123,16-03-2026'."\n"
        );

        $nova = ($this->nova)();

        expect($body)->toBe("Imported: 1 | Failed: 0\n\nCompanies created: 1")
            ->and($nova->company_type_id)->toBe($this->sme->id)
            ->and($nova->industry)->toBe(CompanyIndustry::Software)
            ->and($nova->employees)->toBe(85)
            ->and($nova->annual_revenue)->toBe('1250000.50')
            ->and($nova->phone)->toBe('+33 1 23 45 67 89')
            ->and($nova->address->only(['street', 'city', 'zip', 'country', 'organization_id']))->toBe(['street' => null, 'city' => 'Lyon', 'zip' => null, 'country' => 'France', 'organization_id' => $this->org->id])
            ->and($nova->custom_field_values)->toEqual([$this->vat->key => 'FR123', $this->founded->key => '2026-03-16']);
    });

    it('has no address when no address column is filled', function () {
        ($this->importRow)('company employees,company street,company zip', '10, ,');

        expect(($this->nova)()->address_id)->toBeNull()
            ->and(($this->nova)()->employees)->toBe(10)
            ->and(Address::forOrganization($this->org->id)->count())->toBe(0);
    });

    it('has an address when only one address column is filled', function () {
        ($this->importRow)('company street', '1 Main St');

        expect(($this->nova)()->address->only(['street', 'city', 'zip', 'country']))->toBe(['street' => '1 Main St', 'city' => null, 'zip' => null, 'country' => null]);
    });

    it('reads the industry by its stored value and the type ignoring case and spaces', function () {
        ($this->importRow)('company type,company industry', '" sme ",pharma_biotech');

        expect(($this->nova)()->only(['company_type_id', 'industry']))->toBe(['company_type_id' => $this->sme->id, 'industry' => CompanyIndustry::PharmaBiotech]);
    });

    it('reads the number of employees and the revenue from an Excel file', function () {
        $path = makeXlsx([
            ['name', 'email', 'company', 'company employees', 'company annual revenue'],
            ['Ann', 'ann@x.test', 'Nova', 85, 1250000.5],
        ]);

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        expect(($this->nova)()->only(['employees', 'annual_revenue']))->toBe(['employees' => 85, 'annual_revenue' => '1250000.50']);
    });

    it('fills a select and a multi-select company field from their labels', function () {
        $select = CompanyCustomField::factory()->select()->create(['organization_id' => $this->org->id, 'name' => 'Tier', 'unique' => false, 'order' => 3]);
        $multi = CompanyCustomField::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Regions',
            'type' => 'multiselect',
            'unique' => false,
            'order' => 4,
            'options' => [['label' => 'North', 'value' => 'n'], ['label' => 'South', 'value' => 's']],
        ]);

        ($this->importRow)('company: Tier,company: Regions', '"option 2","North;South"');

        expect(($this->nova)()->custom_field_values)->toEqual([$select->key => 'opt2', $multi->key => ['n', 's']]);
    });

    it('is created with its values in a European file', function () {
        ($this->importFile)("name;email;company;company annual revenue;company: Founded\nAnn;ann@x.test;Nova;1250,5;16/03/2026\n");

        expect(($this->nova)()->annual_revenue)->toBe('1250.50')
            ->and(($this->nova)()->custom_field_values)->toBe([$this->founded->key => '2026-03-16']);
    });

    it('is not counted twice when the same row values come with another company', function () {
        $body = ($this->importFile)("name,email,company,company city\nAnn,ann@x.test,Nova,Lyon\nBob,bob@x.test,Orbit,Paris\n");

        expect($body)->toContain('Companies created: 2')
            ->and(Company::forOrganization($this->org->id)->where('name', 'Orbit')->first()->address->city)->toBe('Paris');
    });
});

describe('an invalid value', function () {
    it('fails the row and creates neither the company, an address nor the contact', function (string $column, string $value) {
        $body = ($this->importRow)("company city,{$column}", "Lyon,\"{$value}\"");

        expect($body)->toContain("Row 1: Invalid value for field '{$column}': {$value}")
            ->and(($this->companyCount)())->toBe(0)
            ->and(($this->contactCount)())->toBe(0)
            ->and(Address::forOrganization($this->org->id)->count())->toBe(0)
            ->and($body)->not->toContain('Companies created');
    })->with([
        'unknown type' => ['company type', 'Giant'],
        'unknown industry' => ['company industry', 'Mining'],
        'employees text' => ['company employees', 'abc'],
        'employees negative' => ['company employees', '-3'],
        'employees decimal' => ['company employees', '1.5'],
        'employees too big' => ['company employees', '2147483648'],
        'revenue text' => ['company annual revenue', '1.2.3'],
        'revenue negative' => ['company annual revenue', '-1'],
        'revenue too big' => ['company annual revenue', '10000000000000'],
        'phone too long' => ['company phone', str_repeat('1', 51)],
        'street too long' => ['company street', str_repeat('a', 256)],
        'country too long' => ['company country', str_repeat('a', 256)],
        'impossible date' => ['company: Founded', '31-02-2026'],
    ]);

    it('does not accept a trashed company type', function () {
        $this->sme->delete();

        $body = ($this->importRow)('company type', 'SME');

        expect($body)->toContain("Invalid value for field 'company type': SME")
            ->and(($this->companyCount)())->toBe(0);
    });

    it('does not fail a row that links an existing company', function () {
        $acme = ($this->makeCompany)('Acme');

        $body = ($this->importFile)("name,email,company,company employees,company type\nAnn,ann@x.test,Acme,abc,Giant\n");

        expect($body)->toStartWith('Imported: 1 | Failed: 0')
            ->and($acme->fresh()->only(['employees', 'company_type_id']))->toBe(['employees' => null, 'company_type_id' => null]);
    });
});

describe('a unique company field', function () {
    it('fails the row when its value is taken', function () {
        ($this->makeCompany)('Old', ['custom_field_values' => [$this->vat->key => 'FR123']]);

        $body = ($this->importRow)('company: VAT Number', 'FR123');

        expect($body)->toContain("Row 1: Duplicate value for unique field 'company: VAT Number'.")
            ->and(($this->companyCount)())->toBe(1)
            ->and(($this->contactCount)())->toBe(0);
    });

    it('fails the row and rolls the address and company back when the value is taken right after the check', function () {
        app(TenantContext::class)->set($this->org->id);
        insertCompetingRowAfterUniquenessCheck('companies', storedRowOf(Company::factory()->make([
            'organization_id' => $this->org->id,
            'name' => 'Competitor',
            'custom_field_values' => [$this->vat->key => 'FR123'],
        ])), 'FR123');
        $service = new ContactImportService($this->org->id);

        $result = $service->processRow(['name' => 'Ann', 'email' => 'ann@x.test', 'company' => 'Nova', 'company city' => 'Lyon', 'company: VAT Number' => 'FR123'], []);

        expect($result)->toBe(['success' => false, 'error' => "Duplicate value for unique field 'company: VAT Number'."])
            ->and(Company::forOrganization($this->org->id)->pluck('name')->all())->toBe(['Competitor'])
            ->and(Address::forOrganization($this->org->id)->count())->toBe(0)
            ->and(($this->contactCount)())->toBe(0)
            ->and($service->createdCompaniesCount())->toBe(0);
    });

    it('lets a second new company of the file fail on the value the first one has', function () {
        $body = ($this->importFile)("name,email,company,company: VAT Number\nAnn,ann@x.test,Nova,FR1\nBob,bob@x.test,Orbit,FR1\n");

        expect($body)->toContain('Imported: 1 | Failed: 1')
            ->and($body)->toContain("Row 2: Duplicate value for unique field 'company: VAT Number'.");
    });
});

describe('an existing company', function () {
    it('is only linked and says so once', function () {
        $acme = ($this->makeCompany)('Acme', ['industry' => CompanyIndustry::FinanceBanking]);

        $body = ($this->importFile)("name,email,company,company industry,company employees\nAnn,ann@x.test,Acme,Software,10\nBob,bob@x.test,Acme,Software,10\n");

        expect($acme->fresh()->only(['industry', 'employees']))->toBe(['industry' => CompanyIndustry::FinanceBanking, 'employees' => null])
            ->and(Contact::forOrganization($this->org->id)->count())->toBe(2)
            ->and($acme->contacts()->count())->toBe(2)
            ->and($body)->toBe("Imported: 2 | Failed: 0\n\nCompany details were not changed for existing companies.");
    });

    it('does not say it when no company detail was filled', function () {
        ($this->makeCompany)('Acme');

        $body = ($this->importFile)("name,email,company,company employees\nAnn,ann@x.test,Acme,\n");

        expect($body)->toBe('Imported: 1 | Failed: 0');
    });

    it('counts a company custom field value as a company detail', function () {
        ($this->makeCompany)('Acme');

        $body = ($this->importFile)("name,email,company,company: VAT Number\nAnn,ann@x.test,Acme,FR9\n");

        expect($body)->toContain('Company details were not changed for existing companies.');
    });

    it('does not say it when the row failed', function () {
        ($this->makeCompany)('Acme');
        Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'ann@x.test']);

        $body = ($this->importFile)("name,email,company,company employees\nAnn,ann@x.test,Acme,10\n");

        expect($body)->not->toContain('Company details were not changed');
    });

    it('is listed with the created companies and before the other lines when the import has errors', function () {
        ($this->makeCompany)('Acme');

        $body = ($this->importFile)("name,email,company,company city,Extra\nAnn,ann@x.test,Acme,Lyon,x\nBob,bob@x.test,Nova,Paris,x\nCid,not-an-email,Nova,,x\n");

        expect($body)->toStartWith("Imported: 2 | Failed: 1\n\nCompanies created: 1\n\nCompany details were not changed for existing companies.\n\nIgnored columns");
    });

    it('is not touched by the first row winning', function () {
        $body = ($this->importFile)("name,email,company,company industry\nAnn,ann@x.test,Nova,Software\nBob,bob@x.test,Nova,Healthcare\n");

        expect(Company::forOrganization($this->org->id)->where('name', 'Nova')->count())->toBe(1)
            ->and(($this->nova)()->industry)->toBe(CompanyIndustry::Software)
            ->and(($this->nova)()->contacts()->count())->toBe(2)
            ->and($body)->toContain('Companies created: 1')
            ->and($body)->toContain('Company details were not changed for existing companies.');
    });

    it('is not reported for a row without a company', function () {
        $body = ($this->importFile)("name,email,company,company employees\nAnn,ann@x.test,,10\n");

        expect($body)->toBe('Imported: 1 | Failed: 0')
            ->and(($this->companyCount)())->toBe(0);
    });
});

describe('the headers', function () {
    it('match the company fields ignoring case and the spaces around the colon', function (string $header) {
        $body = ($this->importFile)("name,email,company,{$header}\nAnn,ann@x.test,Nova,FR123\n");

        expect($body)->not->toContain('Ignored columns')
            ->and(($this->nova)()->custom_field_values)->toBe([$this->vat->key => 'FR123']);
    })->with(['company: VAT Number', 'Company:VAT number', ' COMPANY :   vat number ', 'company : VAT Number']);

    it('report a company field that does not exist as ignored', function () {
        $body = ($this->importRow)('company: Shoe size', '42');

        expect($body)->toContain('Ignored columns (no matching field): "company: Shoe size"')
            ->and(($this->nova)()->custom_field_values)->toBe([]);
    });

    it('say when an ignored company field was deleted', function () {
        $this->vat->delete();

        $body = ($this->importRow)('company: VAT Number', 'FR123');

        expect($body)->toContain('"company: VAT Number" (deleted field)');
    });

    it('fail the file when two headers designate the same company field', function () {
        $body = ($this->importFile)("name,email,company,company: VAT Number,Company:vat number\nAnn,ann@x.test,Nova,A,B\n");

        expect($body)->toContain('Two columns are named "company: VAT Number"')
            ->and(($this->contactCount)())->toBe(0);
    });
});

describe('the template', function () {
    it('imports unchanged and fills the new company', function () {
        $path = ContactImportTemplate::forOrganization($this->org->id)->writeXlsx();
        $storedPath = 'contact-imports/template-'.uniqid().'.xlsx';
        Storage::disk('local')->put($storedPath, file_get_contents($path));
        unlink($path);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($storedPath, $this->org->id, $this->user->id);

        $acme = Company::forOrganization($this->org->id)->where('name', 'Acme Corp')->firstOrFail();

        expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe("Imported: 1 | Failed: 0\n\nCompanies created: 1")
            ->and($acme->only(['company_type_id', 'industry', 'employees', 'annual_revenue', 'phone', 'website']))->toBe([
                'company_type_id' => $this->sme->id,
                'industry' => CompanyIndustry::Software,
                'employees' => 50,
                'annual_revenue' => '1000000.00',
                'phone' => '+1 555 0100',
                'website' => 'acme.com',
            ])
            ->and($acme->address->only(['street', 'city', 'zip', 'country']))->toBe(['street' => '1 Main St', 'city' => 'Springfield', 'zip' => '12345', 'country' => 'United States'])
            ->and($acme->custom_field_values)->toEqual([$this->vat->key => 'Some text', $this->founded->key => now()->startOfYear()->addDays(14)->format('Y-m-d')])
            ->and($acme->contacts()->pluck('email')->all())->toBe(['john@example.com']);
    });

    it('lists the company fields after the contact fields and takes the first company type by name', function () {
        CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Agency']);
        $template = ContactImportTemplate::forOrganization($this->org->id);

        expect(array_slice($template->headers(), 17))->toBe(['company: VAT Number', 'company: Founded'])
            ->and($template->exampleRow()[8])->toBe('Agency');
    });

    it('leaves the company type empty without a company type', function () {
        $this->sme->delete();

        expect(ContactImportTemplate::forOrganization($this->org->id)->exampleRow()[8])->toBe('');
    });
});
