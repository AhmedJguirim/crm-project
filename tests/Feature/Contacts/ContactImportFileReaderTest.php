<?php

use App\Exceptions\UnreadableImportFileException;
use App\Services\ContactImportFileReader;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function readImportFile(string $storedPath): array
{
    $absolutePath = Storage::disk('local')->path($storedPath);

    return iterator_to_array((new ContactImportFileReader)->rows($absolutePath, pathinfo($storedPath, PATHINFO_EXTENSION)), false);
}

function storeImportFile(string $name, string $content): string
{
    $path = "contact-imports/{$name}";
    Storage::disk('local')->put($path, $content);

    return $path;
}

it('yields the same rows for csv and xlsx files', function (string $format) {
    $path = $format === 'csv'
        ? storeImportFile('rows.csv', "name,email,phone,tags\nJane,jane@example.com,123,VIP\nJohn,john@example.com,,\n")
        : makeXlsx([['name', 'email', 'phone', 'tags'], ['Jane', 'jane@example.com', '123', 'VIP'], ['John', 'john@example.com', '', '']]);

    expect(readImportFile($path))->toBe([
        ['name', 'email', 'phone', 'tags'],
        ['Jane', 'jane@example.com', '123', 'VIP'],
        ['John', 'john@example.com', '', ''],
    ]);
})->with(['csv', 'xlsx']);

it('strips a utf-8 bom from the first header of a csv', function () {
    $path = storeImportFile('bom.csv', "\xEF\xBB\xBFname,email\nJane,jane@example.com\n");

    expect(readImportFile($path)[0])->toBe(['name', 'email']);
});

it('normalizes excel cell types to strings', function () {
    $path = makeXlsx([['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], [216555012, 2.5, 3.0, new DateTimeImmutable('1990-03-14'), true, null]]);

    expect(readImportFile($path)[1])->toBe(['216555012', '2.5', '3', '14-03-1990', '1', '']);
});

it('skips empty rows', function () {
    $path = makeXlsx([['name', 'email'], ['Jane', 'jane@example.com'], ['', ''], ['John', 'john@example.com']]);

    expect(readImportFile($path))->toBe([['name', 'email'], ['Jane', 'jane@example.com'], ['John', 'john@example.com']]);
});

it('skips blank lines of a csv', function () {
    $path = storeImportFile('blank.csv', "name,email\nJane,jane@example.com\n\n\nJohn,john@example.com\n");

    expect(readImportFile($path))->toHaveCount(3);
});

it('only reads the first sheet', function () {
    $path = makeXlsx([['name', 'email'], ['Jane', 'jane@example.com']], extraSheets: [[['other', 'rows'], ['x', 'y']]]);

    expect(readImportFile($path))->toBe([['name', 'email'], ['Jane', 'jane@example.com']]);
});

it('rejects unsupported or unreadable files', function (string $name, string $content) {
    $path = storeImportFile($name, $content);

    expect(fn () => readImportFile($path))->toThrow(UnreadableImportFileException::class);
})->with([
    'xls extension' => ['contacts.xls', 'whatever'],
    'text inside an xlsx' => ['contacts.xlsx', 'this is not a spreadsheet'],
]);
