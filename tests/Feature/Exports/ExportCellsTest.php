<?php

use App\Enums\ExportFormat;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Services\Imports\TextPreservingExcelReader;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

it('writes the custom field of a contact the way a person reads it', function (string $type, ?array $options, mixed $stored, ExportFormat $format, string|int|float $expected) {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'type' => $type,
        'unique' => false,
        'options' => $options,
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'custom_field_values' => $stored === null ? [] : [$field->key => $stored],
    ]);

    $cell = ExportCells::customField($contact->fresh(), $field, $format);

    expect($cell)->toBe($expected);
})->with([
    'select label' => ['select', [['label' => 'Gold', 'value' => 'gold']], 'gold', ExportFormat::Xlsx, 'Gold'],
    'select that is no longer an option' => ['select', [['label' => 'Gold', 'value' => 'gold']], 'retired', ExportFormat::Csv, 'retired'],
    'multiselect labels' => ['multiselect', [['label' => 'PHP', 'value' => 'php'], ['label' => 'Vue', 'value' => 'vue']], ['php', 'vue'], ExportFormat::Xlsx, 'PHP;Vue'],
    'date' => ['date', null, '2026-03-16', ExportFormat::Xlsx, '16-03-2026'],
    'number in xlsx' => ['number', null, 25.5, ExportFormat::Xlsx, 25.5],
    'number in csv' => ['number', null, 25.5, ExportFormat::Csv, '25.5'],
    'whole number in csv' => ['number', null, 1500000, ExportFormat::Csv, '1500000'],
    'decimal string with zeros in csv' => ['number', null, '1500000.00', ExportFormat::Csv, '1500000'],
    'text' => ['text', null, 'hello', ExportFormat::Csv, 'hello'],
    'empty value' => ['text', null, null, ExportFormat::Xlsx, ''],
]);

it('writes the custom field of a company with the same code', function () {
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'type' => 'multiselect',
        'unique' => false,
        'options' => [['label' => 'Cloud', 'value' => 'cloud'], ['label' => 'Security', 'value' => 'security']],
    ]);
    $company = Company::factory()->create([
        'organization_id' => $this->org->id,
        'custom_field_values' => [$field->key => ['cloud', 'security']],
    ]);

    expect(ExportCells::customField($company->fresh(), $field, ExportFormat::Xlsx))->toBe('Cloud;Security');
});

it('guards a CSV cell that a spreadsheet app would evaluate', function (string $value, string $safe) {
    expect(ExportCells::csvSafe($value))->toBe($safe);
})->with([
    ['=1+1', "'=1+1"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ['-cmd', "'-cmd"],
    ['+33 1 23', '+33 1 23'],
    ['-12.5', '-12.5'],
    ['Acme', 'Acme'],
]);

it('guards the headers and the text cells of a CSV only', function () {
    expect(ExportCells::headers(['=x', 'name'], ExportFormat::Csv))->toBe(["'=x", 'name'])
        ->and(ExportCells::headers(['=x', 'name'], ExportFormat::Xlsx))->toBe(['=x', 'name'])
        ->and(ExportCells::row(['=1', 5, 'a'], ExportFormat::Csv))->toBe(["'=1", 5, 'a'])
        ->and(ExportCells::row(['=1', 5, 'a'], ExportFormat::Xlsx))->toBe(['=1', 5, 'a']);
});

it('writes the header, then every record of every chunk in order, and counts them', function () {
    $path = tempnam(sys_get_temp_dir(), 'export-cells').'.xlsx';

    $count = ExportFileWriter::write(
        $path,
        ['name', 'rank'],
        [['Ann', 'Bob'], ['Cy']],
        fn (string $name): array => [$name, 7],
    );

    $rows = TextPreservingExcelReader::create($path, 'xlsx')->noHeaderRow()->getRows()->values()->all();
    unlink($path);

    expect($count)->toBe(3)
        ->and($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['name', 'rank'])
        ->and(array_column($rows, 0))->toBe(['name', 'Ann', 'Bob', 'Cy'])
        ->and($rows[1][1])->toBeNumeric()->not->toBeString();
});

it('starts a CSV with the UTF-8 BOM', function () {
    $path = tempnam(sys_get_temp_dir(), 'export-cells').'.csv';

    ExportFileWriter::write($path, ['name'], [['Hélène']], fn (string $name): array => [$name]);

    $contents = file_get_contents($path);
    unlink($path);

    expect($contents)->toStartWith("\xEF\xBB\xBFname\n")->toContain('Hélène');
});
