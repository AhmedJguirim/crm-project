<?php

use App\Enums\CompanyIndustry;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Support\CustomFields\CustomFieldDefinitionFields;
use App\Jobs\ProcessCompanyImportJob;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\User;
use App\Services\CompanyImportTemplate;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->companyField = fn (string $type, string $name, int $order, array $attributes = []): CompanyCustomField => CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'type' => $type,
        'unique' => false,
        'order' => $order,
        ...$attributes,
    ]);

    $this->importTemplateUnchanged = function (): DatabaseNotification {
        $path = CompanyImportTemplate::forOrganization($this->org->id)->writeXlsx();
        Storage::disk('local')->put('company-imports/template.xlsx', file_get_contents($path));
        unlink($path);

        DatabaseNotification::query()->delete();
        ProcessCompanyImportJob::dispatchSync('company-imports/template.xlsx', $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };
});

it('imports the template unchanged, with every custom field type', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $fields = [];

    foreach (array_keys(CustomFieldDefinitionFields::TYPES) as $index => $fieldType) {
        $fields[$fieldType] = ($this->companyField)($fieldType, "Field {$fieldType}", $index + 1, match ($fieldType) {
            'select' => ['options' => [['label' => 'Monthly', 'value' => 'Monthly'], ['label' => 'Per day, flat', 'value' => 'Per day, flat']]],
            'multiselect' => ['options' => [['label' => 'laravel', 'value' => 'laravel'], ['label' => 'php', 'value' => 'php']]],
            default => [],
        });
    }

    ($this->companyField)('text', 'Old field', 20)->delete();

    $notification = ($this->importTemplateUnchanged)();
    $acme = Company::forOrganization($this->org->id)->sole();
    $values = $acme->custom_field_values;

    expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and($acme->name)->toBe('Acme Corp')
        ->and($acme->domain)->toBe('acme.com')
        ->and($acme->company_type_id)->toBe($type->id)
        ->and($acme->phone)->toBe('+1 555 0100')
        ->and($acme->industry)->toBe(CompanyIndustry::Software)
        ->and($acme->employees)->toBe(50)
        ->and((string) $acme->annual_revenue)->toBe('1000000.00')
        ->and($acme->notes)->toBe('Key account')
        ->and(Address::forOrganization($this->org->id)->sole()->city)->toBe('Springfield')
        ->and($values[$fields['text']->key])->toBe('Some text')
        ->and($values[$fields['number']->key])->toEqual(42)
        ->and($values[$fields['date']->key])->toBe(now()->startOfYear()->addDays(14)->format('Y-m-d'))
        ->and($values[$fields['select']->key])->toBe('Monthly')
        ->and($values[$fields['multiselect']->key])->toEqual(['laravel', 'php'])
        ->and($values)->toHaveCount(count(CustomFieldDefinitionFields::TYPES));
});

it('imports the template of an organization without types or custom fields, and ignores the formatted empty rows', function () {
    expect(($this->importTemplateUnchanged)()->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and(Company::forOrganization($this->org->id)->count())->toBe(1)
        ->and(Company::forOrganization($this->org->id)->sole()->company_type_id)->toBeNull();
});

it('lists the import columns then the active custom fields, in order', function () {
    ($this->companyField)('text', 'Second', 2);
    ($this->companyField)('text', 'First', 1);
    ($this->companyField)('text', 'Gone', 3)->delete();

    expect(CompanyImportTemplate::forOrganization($this->org->id)->headers())
        ->toBe(['name', 'website', 'type', 'phone', 'industry', 'employees', 'annual revenue', 'street', 'city', 'zip', 'country', 'notes', 'First', 'Second']);
});

it('has typed cells and the example row of the ticket', function () {
    $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    ($this->companyField)('date', 'Founded', 1);
    ($this->companyField)('number', 'Budget', 2);

    $path = CompanyImportTemplate::forOrganization($this->org->id)->writeXlsx();
    $rows = SimpleExcelReader::create($path, 'xlsx')->noHeaderRow()->take(2)->getRows()->values()->all();

    unlink($path);

    expect(array_slice($rows[1], 0, 12))->toBe(['Acme Corp', 'acme.com', 'SME', '+1 555 0100', 'Software', 50, 1000000, '1 Main St', 'Springfield', '12345', 'United States', 'Key account'])
        ->and($rows[1][12])->toBeInstanceOf(DateTimeInterface::class)
        ->and($rows[1][12]->format('d-m H:i:s'))->toBe('15-01 00:00:00')
        ->and($rows[1][13])->toBe(42)
        ->and($type->name)->toBe('SME');
});

it('formats the text and date columns, including the empty rows below the example', function () {
    ($this->companyField)('date', 'Founded', 1);
    ($this->companyField)('number', 'Budget', 2);

    $path = CompanyImportTemplate::forOrganization($this->org->id)->writeXlsx();
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = new SimpleXMLElement($zip->getFromName('xl/styles.xml'));
    $zip->close();
    unlink($path);

    $format = function (string $cell) use ($sheet, $styles): string|int {
        preg_match('/<c r="'.$cell.'"[^>]*? s="(\d+)"/', $sheet, $matches);

        if (! isset($matches[1])) {
            return 'no style';
        }

        $formatId = (int) $styles->cellXfs->xf[(int) $matches[1]]['numFmtId'];

        foreach ($styles->numFmts->numFmt ?? [] as $custom) {
            if ((int) $custom['numFmtId'] === $formatId) {
                return (string) $custom['formatCode'];
            }
        }

        return $formatId;
    };

    expect([
        'name, last empty row' => $format('A1002'),
        'phone, example row' => $format('D2'),
        'employees, last empty row' => $format('F1002'),
        'annual revenue, last empty row' => $format('G1002'),
        'notes, last empty row' => $format('L1002'),
        'founded, last empty row' => $format('M1002'),
        'budget, last empty row' => $format('N1002'),
    ])->toBe([
        'name, last empty row' => 49,
        'phone, example row' => 49,
        'employees, last empty row' => 'no style',
        'annual revenue, last empty row' => 'no style',
        'notes, last empty row' => 49,
        'founded, last empty row' => 'dd-mm-yyyy',
        'budget, last empty row' => 'no style',
    ]);
});

it('is returned by the download action', function () {
    Livewire::test(ListCompanies::class)
        ->callAction('downloadTemplate')
        ->assertFileDownloaded('companies-import-template.xlsx');
});
