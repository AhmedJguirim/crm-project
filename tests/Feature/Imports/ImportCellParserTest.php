<?php

use App\Enums\CompanyIndustry;
use App\Models\CustomField;
use App\Services\Imports\ImportCellParser;
use App\Support\CsvDialect;

beforeEach(function () {
    $this->parserFor = fn (string $delimiter): ImportCellParser => new ImportCellParser(1, new CsvDialect($delimiter));
    $this->selectField = fn (): CustomField => CustomField::factory()->make([
        'type' => 'select',
        'options' => [['label' => 'Active', 'value' => 'opt_x'], ['label' => 'Dormant', 'value' => 'opt_y']],
    ]);
});

it('reads a decimal comma only in a European file', function (?string $delimiter, string $raw, ?float $expected) {
    $parser = $delimiter === null ? new ImportCellParser(1) : ($this->parserFor)($delimiter);

    expect($parser->parseNumber($raw))->toBe($expected);
})->with([
    'European comma' => [';', '25,5', 25.5],
    'comma file, comma number' => [',', '25,5', null],
    'no dialect, comma number' => [null, '25,5', null],
    'comma file, dot number' => [',', '25.5', 25.5],
]);

it('refuses an impossible date and formats a valid one as ISO', function () {
    $parser = ($this->parserFor)(',');

    expect($parser->parseDate('31-02-2026'))->toBeFalse()
        ->and($parser->formatDate('31-02-2026'))->toBeNull()
        ->and($parser->formatDate('16-03-2026'))->toBe('2026-03-16')
        ->and($parser->formatDate('2026-03-16'))->toBe('2026-03-16');
});

it('reads d/m/Y dates only in a European file', function () {
    expect(($this->parserFor)(';')->formatDate('16/03/2026'))->toBe('2026-03-16')
        ->and(($this->parserFor)(',')->formatDate('16/03/2026'))->toBeNull();
});

it('finds a select option by label or by stored value', function () {
    $parser = ($this->parserFor)(',');
    $field = ($this->selectField)();

    expect($parser->parseSelectValue($field, ' active '))->toBe('opt_x')
        ->and($parser->parseSelectValue($field, 'opt_x'))->toBe('opt_x')
        ->and($parser->parseSelectValue($field, 'unknown'))->toBeNull();
});

it('splits a multi-select cell and refuses a value that is not an option', function () {
    $parser = ($this->parserFor)(',');
    $field = ($this->selectField)();

    expect($parser->parseMultiselectValue($field, 'active; opt_x ;Dormant'))->toBe(['opt_x', 'opt_y'])
        ->and($parser->parseMultiselectValue($field, 'active;nope'))->toBeNull()
        ->and($parser->splitMultiValue(' a;; b ;'))->toBe(['a', 'b']);
});

it('finds an enum case by label or value, ignoring case and spaces', function () {
    $parser = ($this->parserFor)(',');

    expect($parser->enumFromCell(CompanyIndustry::class, ' software '))->toBe(CompanyIndustry::Software)
        ->and($parser->enumFromCell(CompanyIndustry::class, 'finance_banking'))->toBe(CompanyIndustry::FinanceBanking)
        ->and($parser->enumFromCell(CompanyIndustry::class, ' finance & BANKING '))->toBe(CompanyIndustry::FinanceBanking)
        ->and($parser->enumFromCell(CompanyIndustry::class, 'not an industry'))->toBeNull();
});

it('reads the number of employees and the annual revenue', function () {
    $european = ($this->parserFor)(';');

    expect($european->parseEmployees('-1'))->toBeNull()
        ->and($european->parseEmployees('85'))->toBe(85)
        ->and($european->parseEmployees('2147483648'))->toBeNull()
        ->and($european->parseAnnualRevenue('1250,5'))->toBe('1250.50')
        ->and($european->parseAnnualRevenue('-1'))->toBeNull()
        ->and($european->parseAnnualRevenue('10000000000000'))->toBeNull()
        ->and(($this->parserFor)(',')->parseAnnualRevenue('1250,5'))->toBeNull();
});

it('casts a custom field value by its type', function (string $type, string $raw, mixed $expected) {
    $field = CustomField::factory()->make(['type' => $type]);

    expect(($this->parserFor)(',')->castFieldValue($field, $raw))->toBe($expected);
})->with([
    'email ok' => ['email', 'a@b.co', 'a@b.co'],
    'email bad' => ['email', 'nope', null],
    'url bad' => ['url', 'nope', null],
    'phone' => ['phone', '+33 6 12-34', '+3361234'],
    'phone without digits' => ['phone', 'abc', null],
    'number' => ['number', '42', 42.0],
    'text is trimmed' => ['text', ' hi ', 'hi'],
]);
