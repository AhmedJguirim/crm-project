<?php

use App\Exceptions\UnreadableImportFileException;
use App\Services\ContactImportFileReader;
use App\Support\CsvDialect;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use Spatie\SimpleExcel\SimpleExcelWriter;

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

function dialectOfImportFile(string $content, string $name = 'dialect.csv'): CsvDialect
{
    $path = storeImportFile($name, $content);

    return (new ContactImportFileReader)->dialect(Storage::disk('local')->path($path), 'csv');
}

describe('the dialect of a csv file', function () {
    it('is read from the bytes', function (string $content, string $delimiter, string $encoding) {
        $dialect = dialectOfImportFile($content);

        expect($dialect->delimiter)->toBe($delimiter)
            ->and($dialect->encoding)->toBe($encoding);
    })->with([
        'comma, utf-8' => ["name,email\nAnn,a@x.test\n", ',', 'UTF-8'],
        'semicolon, utf-8' => ["name;email\nAnn;a@x.test\n", ';', 'UTF-8'],
        'semicolon, windows-1252' => [iconv('UTF-8', 'Windows-1252', "name;email;Taux (€)\nHélène;a@x.test;25,5\n"), ';', 'Windows-1252'],
        'semicolon after a byte order mark' => ["\xEF\xBB\xBFname;email\nAnn;a@x.test\n", ';', 'UTF-8'],
        'a byte order mark makes it utf-8 whatever follows' => ["\xEF\xBB\xBFname;email\nH\xE9l\xE8ne;a@x.test\n", ';', 'UTF-8'],
        'quoted semicolon in a comma file' => ["\"a;b\",email,phone\nx,y,z\n", ',', 'UTF-8'],
        'quoted comma in a semicolon file' => ["\"a,b,c\";email;phone\nx;y;z\n", ';', 'UTF-8'],
        'quoted header with a line break' => ["\"Notes\nmore\";email;phone\nx;y;z\n", ';', 'UTF-8'],
        'a doubled quote inside a quoted header' => ["\"say \"\"a;b;c\"\"\",email,phone\nx,y,z\n", ',', 'UTF-8'],
        'a tie stays a comma file' => ["name;email,phone\nx;y,z\n", ',', 'UTF-8'],
        'only the header line counts' => ["name,email\nAnn;a;b;c;d\n", ',', 'UTF-8'],
        'windows line breaks' => ["name;email\r\nAnn;a@x.test\r\n", ';', 'UTF-8'],
        'a single column' => ["name\nAnn\n", ',', 'UTF-8'],
        'an empty file' => ['', ',', 'UTF-8'],
        'binary bytes' => ["\x00\xFF\xFE\x80\x81", ',', 'Windows-1252'],
    ]);

    it('selects windows-1252 when invalid utf-8 only comes late in the file', function () {
        $lines = collect(range(1, 1000))->map(fn (int $number): string => "Person {$number};person{$number}@x.test")->implode("\n");
        $dialect = dialectOfImportFile("name;email\n{$lines}\n".iconv('UTF-8', 'Windows-1252', 'Société').";late@x.test\n");

        expect($dialect->delimiter)->toBe(';')
            ->and($dialect->encoding)->toBe('Windows-1252');
    });

    it('is the default one for an excel file', function () {
        $path = makeXlsx([['name', 'email']]);

        $dialect = (new ContactImportFileReader)->dialect(Storage::disk('local')->path($path), 'xlsx');

        expect($dialect->delimiter)->toBe(',')
            ->and($dialect->encoding)->toBe('UTF-8');
    });

    it('is the default one for a file that does not exist', function () {
        $dialect = (new ContactImportFileReader)->dialect(Storage::disk('local')->path('contact-imports/missing.csv'), 'csv');

        expect($dialect->delimiter)->toBe(',');
    });

    it('reads the rows of a windows-1252 file with semicolons', function () {
        $path = storeImportFile('french.csv', iconv('UTF-8', 'Windows-1252', "name;email;Taux (€)\n\"Hélène Dupré\";helene@x.test;\"25,5\"\n"));

        expect(readImportFile($path))->toBe([
            ['name', 'email', 'Taux (€)'],
            ['Hélène Dupré', 'helene@x.test', '25,5'],
        ]);
    });

    it('reads the rows of a utf-8 file with semicolons and keeps a quoted semicolon in its cell', function () {
        $path = storeImportFile('german.csv', "name;email;tags\nAnn;ann@x.test;\"VIP;Newsletter\"\n");

        expect(readImportFile($path))->toBe([
            ['name', 'email', 'tags'],
            ['Ann', 'ann@x.test', 'VIP;Newsletter'],
        ]);
    });
});

it('reads a text cell that starts with an equals sign as text', function () {
    $absolute = Storage::disk('local')->path('contact-imports/equals-text.xlsx');
    Storage::disk('local')->makeDirectory('contact-imports');

    $writer = SimpleExcelWriter::create($absolute)->noHeaderRow();
    $writer->addRow(new Row([
        new StringCell('name', null),
        new StringCell('=1+1', null),
    ]));
    $writer->close();

    expect(readImportFile('contact-imports/equals-text.xlsx'))->toBe([['name', '=1+1']]);
});

it('reads a formula with a stored result as that result', function () {
    $path = makeXlsx([['name', '=1+1']]);
    $absolute = Storage::disk('local')->path($path);

    $zip = new ZipArchive;
    $zip->open($absolute);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<f>1+1</f>', '<f>1+1</f><v>2</v>', $sheet));
    $zip->close();

    expect($sheet)->toContain('<f>1+1</f>')
        ->and(readImportFile($path))->toBe([['name', '2']]);
});
