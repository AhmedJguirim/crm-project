<?php

use App\Enums\CompanyIndustry;
use App\Enums\ExportFormat;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\User;
use App\Services\CompanyExportWriter;
use App\Services\Imports\TextPreservingExcelReader;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->field = fn (string $type, string $name, int $order, ?array $options = null): CompanyCustomField => CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'type' => $type,
        'unique' => false,
        'order' => $order,
        'options' => $options,
    ]);

    $this->funding = ($this->field)('select', 'Funding', 1, [['label' => 'Seed', 'value' => 'seed'], ['label' => 'Series A', 'value' => 'series_a']]);
    $this->specialties = ($this->field)('multiselect', 'Specialties', 2, [['label' => 'PHP', 'value' => 'php'], ['label' => 'Vue', 'value' => 'vue']]);
    $this->founded = ($this->field)('date', 'Founded', 3);
    $this->band = ($this->field)('number', 'Headcount band', 4);

    $this->type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Client']);

    $this->acme = Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Acme Corp',
        'website' => 'https://www.acme.com',
        'company_type_id' => $this->type->id,
        'phone' => '+33 1 23 45 67 89',
        'industry' => CompanyIndustry::Software,
        'employees' => 120,
        'annual_revenue' => '1500000.50',
        'notes' => "Line 1\nLine 2",
        'address_id' => Address::factory()->create([
            'organization_id' => $this->org->id,
            'street' => '1 Main St',
            'city' => 'Lyon',
            'zip' => '69001',
            'country' => 'France',
        ])->id,
        'custom_field_values' => [
            $this->funding->key => 'series_a',
            $this->specialties->key => ['php', 'vue'],
            $this->founded->key => '2019-05-04',
            $this->band->key => 2.5,
        ],
    ]);
    $this->acme->contacts()->attach(collect(['Ann', 'Bob', 'Cid'])->map(fn (string $name): int => Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $name])->id)->all());
    Contact::query()->where('name', 'Cid')->first()->delete();

    $this->writer = fn (): CompanyExportWriter => new CompanyExportWriter(CompanyCustomField::forOrganization($this->org->id)->orderBy('order')->get());
    $this->load = fn (Company $company): Company => Company::query()->withCount('contacts')->with(['address', 'companyTypeWithTrashed'])->findOrFail($company->id);
});

it('names the columns like the import, then contacts, then the custom fields', function () {
    expect(($this->writer)()->headers())->toBe(['name', 'website', 'type', 'phone', 'industry', 'employees', 'annual revenue', 'street', 'city', 'zip', 'country', 'notes', 'contacts', 'Funding', 'Specialties', 'Founded', 'Headcount band']);
});

it('writes the cells of a company as numbers in an xlsx', function () {
    expect(($this->writer)()->row(($this->load)($this->acme), ExportFormat::Xlsx))->toBe([
        'Acme Corp', 'https://www.acme.com', 'Client', '+33 1 23 45 67 89', 'Software', 120, 1500000.5,
        '1 Main St', 'Lyon', '69001', 'France', "Line 1\nLine 2", 2, 'Series A', 'PHP;Vue', '04-05-2019', 2.5,
    ]);
});

it('writes the numbers as text in a csv', function () {
    $row = ($this->writer)()->row(($this->load)($this->acme), ExportFormat::Csv);

    expect($row[5])->toBe('120')
        ->and($row[6])->toBe('1500000.5')
        ->and($row[12])->toBe(2)
        ->and($row[16])->toBe('2.5')
        ->and($row[0])->toBe('Acme Corp');
});

it('writes a whole annual revenue without decimals in a csv', function () {
    $this->acme->update(['annual_revenue' => '1500000.00']);

    expect(($this->writer)()->row(($this->load)($this->acme), ExportFormat::Csv)[6])->toBe('1500000');
});

it('writes empty cells for a company with little data', function () {
    $bare = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bare', 'notes' => null, 'custom_field_values' => []]);

    $row = ($this->writer)()->row(($this->load)($bare), ExportFormat::Xlsx);

    expect($row)->toBe(['Bare', '', '', '', '', '', '', '', '', '', '', '', 0, '', '', '', '']);
});

it('keeps the plain name of a deleted type', function () {
    $this->type->delete();

    expect(($this->writer)()->row(($this->load)($this->acme), ExportFormat::Xlsx)[2])->toBe('Client');
});

it('reads a deleted address as none', function () {
    $this->acme->address->delete();

    expect(array_slice(($this->writer)()->row(($this->load)($this->acme), ExportFormat::Xlsx), 7, 4))->toBe(['', '', '', '']);
});

it('keeps a text that starts like a formula safe', function () {
    $tricky = Company::factory()->create(['organization_id' => $this->org->id, 'name' => '=1+1', 'notes' => '@home', 'custom_field_values' => []]);

    $csv = ($this->writer)()->row(($this->load)($tricky), ExportFormat::Csv);
    $xlsx = ($this->writer)()->row(($this->load)($tricky), ExportFormat::Xlsx);

    expect($csv[0])->toBe("'=1+1")
        ->and($csv[11])->toBe("'@home")
        ->and($xlsx[0])->toBe('=1+1')
        ->and(($this->writer)()->headers(ExportFormat::Csv)[0])->toBe('name');
});

it('writes the header and the companies of every chunk to an xlsx and a csv', function (ExportFormat $format) {
    $path = tempnam(sys_get_temp_dir(), 'company-export').'.'.$format->value;
    $other = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Other', 'custom_field_values' => []]);

    $count = ($this->writer)()->write($path, $format, [[($this->load)($this->acme)], [($this->load)($other)]]);
    $contents = file_get_contents($path);
    $rows = TextPreservingExcelReader::create($path, $format->value)->noHeaderRow()->getRows()->values()->all();
    unlink($path);

    expect($count)->toBe(2)
        ->and($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(($this->writer)()->headers($format))
        ->and(array_column($rows, 0))->toBe(['name', 'Acme Corp', 'Other']);

    if ($format === ExportFormat::Csv) {
        expect($contents)->toStartWith("\xEF\xBB\xBFname,website,type,phone,industry,employees,");
    }
})->with([ExportFormat::Xlsx, ExportFormat::Csv]);
