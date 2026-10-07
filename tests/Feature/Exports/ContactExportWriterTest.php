<?php

use App\Enums\ContactStatus;
use App\Enums\ExportFormat;
use App\Enums\LeadSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactExportWriter;
use App\Services\Imports\TextPreservingExcelReader;
use Filament\Facades\Filament;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->field = fn (string $type, string $name, int $order, array $attributes = []): CustomField => CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'type' => $type,
        'unique' => false,
        'order' => $order,
        ...$attributes,
    ]);

    $this->plan = ($this->field)('select', 'Plan', 1, ['options' => [['label' => 'Gold', 'value' => 'gold'], ['label' => 'Silver', 'value' => 'silver']]]);
    $this->stack = ($this->field)('multiselect', 'Stack', 2, ['options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'Vue', 'value' => 'vue']]]);
    $this->since = ($this->field)('date', 'Since', 3);
    $this->rate = ($this->field)('number', 'Rate', 4);

    $this->ann = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Ann',
        'email' => 'ann@x.test',
        'phone' => '+33 1',
        'status' => ContactStatus::ActiveClient,
        'lead_source' => LeadSource::LinkedIn,
        'custom_field_values' => [
            $this->plan->key => 'gold',
            $this->stack->key => ['php', 'vue'],
            $this->since->key => '2026-03-16',
            $this->rate->key => 25.5,
        ],
    ]);
    $this->ann->tags()->sync([
        Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP'])->id,
        Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'Newsletter'])->id,
    ]);
    $this->ann->companies()->attach([
        Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zeta', 'website' => null])->id,
        Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme', 'website' => 'acme.com'])->id,
    ]);

    $this->writer = fn (): ContactExportWriter => new ContactExportWriter(CustomField::forOrganization($this->org->id)->orderBy('order')->get());
    $this->rowOf = fn (Contact $contact, ExportFormat $format = ExportFormat::Csv): array => ($this->writer)()->row($contact->fresh(['tags', 'companies']), $format);
});

it('has the import columns, then the active custom fields by name', function () {
    ($this->field)('text', 'Gone', 5)->delete();

    expect(($this->writer)()->headers())->toBe(['name', 'email', 'phone', 'status', 'lead source', 'tags', 'company', 'company website', 'all companies', 'Plan', 'Stack', 'Since', 'Rate']);
});

it('writes the values the way a person reads them', function () {
    expect(($this->rowOf)($this->ann))->toBe(['Ann', 'ann@x.test', '+33 1', 'Active Client', 'LinkedIn', 'Newsletter;VIP', 'Acme', 'acme.com', 'Acme; Zeta', 'Gold', 'PHP;Vue', '16-03-2026', '25.5']);
});

it('leaves blank what a contact does not have', function () {
    $bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob', 'email' => 'bob@x.test', 'phone' => null, 'status' => null, 'lead_source' => null]);

    expect(($this->rowOf)($bob))->toBe(['Bob', 'bob@x.test', '', '', '', '', '', '', '', '', '', '', '']);
});

it('names all the companies only when there are two or more', function () {
    $solo = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Solo', 'email' => 'solo@x.test']);
    $solo->companies()->attach(Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Only', 'website' => 'https://Only.io/about'])->id);

    expect(($this->rowOf)($solo)[6])->toBe('Only')
        ->and(($this->rowOf)($solo)[7])->toBe('https://Only.io/about')
        ->and(($this->rowOf)($solo)[8])->toBe('');
});

it('leaves out the trashed companies and tags', function () {
    $this->ann->companies()->first()->delete();
    $this->ann->tags()->first()->delete();

    $row = ($this->rowOf)($this->ann);

    expect($row[5])->not->toContain(';')
        ->and($row[8])->toBe('');
});

it('writes a value that is no longer an option as stored', function () {
    $this->ann->update(['custom_field_values' => [
        $this->plan->key => 'platinum',
        $this->stack->key => ['php', 'cobol'],
    ]]);

    $row = ($this->rowOf)($this->ann);

    expect($row[9])->toBe('platinum')
        ->and($row[10])->toBe('PHP;cobol');
});

it('writes numbers with a decimal point, no thousands separator and no trailing zero', function (mixed $stored, string $expected) {
    $this->ann->update(['custom_field_values' => [$this->rate->key => $stored]]);

    expect(($this->rowOf)($this->ann)[12])->toBe($expected);
})->with([
    'integer' => [42, '42'],
    'whole float' => [42.0, '42'],
    'decimal' => [25.5, '25.5'],
    'thousands' => [1234567.25, '1234567.25'],
    'negative' => [-0.5, '-0.5'],
    'numeric string' => ['7.50', '7.5'],
]);

it('writes number fields as numeric cells in an xlsx, and everything else as text', function () {
    $row = ($this->rowOf)($this->ann, ExportFormat::Xlsx);

    expect($row[12])->toBe(25.5)
        ->and($row[2])->toBe('+33 1')
        ->and($row[11])->toBe('16-03-2026');
});

it('writes a csv with a BOM, UTF-8 text and the header row', function () {
    $helene = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Hélène', 'email' => 'h@x.test', 'phone' => null, 'custom_field_values' => []]);
    $text = ($this->field)('text', 'Budget', 5);
    $helene->update(['custom_field_values' => [$text->key => '50 €']]);
    $path = tempnam(sys_get_temp_dir(), 'export-test').'.csv';

    $count = ($this->writer)()->write($path, ExportFormat::Csv, [[$helene->fresh(['tags', 'companies'])]]);
    $contents = file_get_contents($path);
    unlink($path);

    expect($count)->toBe(1)
        ->and($contents)->toStartWith("\xEF\xBB\xBFname,email,phone,status,\"lead source\",tags,company,\"company website\",\"all companies\",Plan,Stack,Since,Rate,Budget\n")
        ->and($contents)->toContain('Hélène')
        ->and($contents)->toContain('50 €');
});

it('writes an xlsx whose number cells are numeric and whose header is bold', function () {
    $path = tempnam(sys_get_temp_dir(), 'export-test').'.xlsx';

    ($this->writer)()->write($path, ExportFormat::Xlsx, [[$this->ann->fresh(['tags', 'companies'])]]);

    $rows = TextPreservingExcelReader::create($path, 'xlsx')->noHeaderRow()->getRows()->values()->all();
    $zip = new ZipArchive;
    $zip->open($path);
    $styles = $zip->getFromName('xl/styles.xml');
    $zip->close();
    unlink($path);

    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('name')
        ->and($rows[1][12])->toBe(25.5)
        ->and($rows[1][2])->toBe('+33 1')
        ->and($styles)->toContain('<b/>');
});

it('writes text that starts with an equals sign as a text cell in an xlsx, never as a formula', function () {
    $formulaField = ($this->field)('text', '=SUM(1,1)', 5);
    $evil = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => '=HYPERLINK("http://evil.test","x")',
        'email' => 'evil@x.test',
        'phone' => null,
        'custom_field_values' => [$formulaField->key => '=1+1'],
    ]);
    $evil->companies()->attach(Company::factory()->create(['organization_id' => $this->org->id, 'name' => '=cmd', 'website' => null])->id);
    $path = tempnam(sys_get_temp_dir(), 'export-test').'.xlsx';

    ($this->writer)()->write($path, ExportFormat::Xlsx, [[$evil->fresh(['tags', 'companies'])]]);

    $rows = TextPreservingExcelReader::create($path, 'xlsx')->noHeaderRow()->getRows()->values()->all();
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->not->toContain('<f>')
        ->and($rows[1][0])->toBe('=HYPERLINK("http://evil.test","x")')
        ->and($rows[1][6])->toBe('=cmd')
        ->and($rows[1][13])->toBe('=1+1')
        ->and($rows[0][13])->toBe('=SUM(1,1)');
});

it('prefixes the csv cells a spreadsheet app would evaluate, and leaves the others as stored', function (string $value, string $expected) {
    expect(ContactExportWriter::csvSafe($value))->toBe($expected);
})->with([
    'equals' => ['=1+1', "'=1+1"],
    'at' => ['@x', "'@x"],
    'minus function' => ['-1+cmd|x', "'-1+cmd|x"],
    'plus function' => ['+cmd', "'+cmd"],
    'leading space' => [' =x', "' =x"],
    'tab' => ["\tx", "'\tx"],
    'carriage return' => ["\rx", "'\rx"],
    'phone' => ['+33 1', '+33 1'],
    'phone with brackets' => ['+1 (555) 010-0000', '+1 (555) 010-0000'],
    'negative number' => ['-5', '-5'],
    'negative decimal' => ['-0.5', '-0.5'],
    'plain text' => ['Hélène', 'Hélène'],
    'equals inside' => ['a=b', 'a=b'],
    'empty' => ['', ''],
]);

it('prefixes every kind of csv cell: contact, company, custom field value and header', function () {
    $formulaField = ($this->field)('text', '=x', 5);
    $evil = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => '=1+1',
        'email' => 'evil@x.test',
        'phone' => '+33 1',
        'custom_field_values' => [$formulaField->key => '@y'],
    ]);
    $evil->companies()->attach(Company::factory()->create(['organization_id' => $this->org->id, 'name' => '=Acme', 'website' => null])->id);
    $evil->companies()->attach(Company::factory()->create(['organization_id' => $this->org->id, 'name' => '-cmd', 'website' => null])->id);
    $path = tempnam(sys_get_temp_dir(), 'export-test').'.csv';

    ($this->writer)()->write($path, ExportFormat::Csv, [[$evil->fresh(['tags', 'companies'])]]);

    $rows = SimpleExcelReader::create($path, 'csv')->noHeaderRow()->getRows()->values()->all();
    unlink($path);

    expect($rows[0][13])->toBe("'=x")
        ->and($rows[1][0])->toBe("'=1+1")
        ->and($rows[1][2])->toBe('+33 1')
        ->and($rows[1][6])->toBe("'-cmd")
        ->and($rows[1][8])->toBe("'-cmd; =Acme")
        ->and($rows[1][13])->toBe("'@y");
});
