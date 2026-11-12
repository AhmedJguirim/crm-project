<?php

use App\Jobs\Concerns\ReportsImportResults;
use App\Support\CsvDialect;
use App\Support\TemporaryFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    $this->temporaryDirectory = sys_get_temp_dir().'/crm-test-tmp-'.Str::random(12);
    mkdir($this->temporaryDirectory);
    TemporaryFile::useDirectory($this->temporaryDirectory);

    $this->reporter = new class
    {
        use ReportsImportResults;

        public string $filePath = 'imports/source.csv';

        public int $userId = 7;

        public function call(string $method, mixed ...$arguments): mixed
        {
            return $this->{$method}(...$arguments);
        }

        /**
         * @param  array<int, array{row: int, data: array<string, string>, error: string}>  $failedRows
         * @param  array<string, string>  $data
         */
        public function keep(array &$failedRows, int $rowNumber, array $data, string $error): void
        {
            $this->keepFailedRow($failedRows, $rowNumber, $data, $error);
        }
    };
});

afterEach(function () {
    TemporaryFile::useDirectory(null);

    array_map('unlink', glob($this->temporaryDirectory.'/*') ?: []);
    rmdir($this->temporaryDirectory);
});

it('copies the upload to a temporary file named with the given prefix and extension', function () {
    Storage::disk('local')->put('imports/source.csv', "name\nAnn\n");

    $path = $this->reporter->call('copyToTemporaryFile', 'csv', 'company-import-');

    expect(basename($path))->toStartWith('company-import-')
        ->and($path)->toEndWith('.csv')
        ->and(file_get_contents($path))->toBe("name\nAnn\n");
});

it('writes the failed rows in the delimiter of the source file, with the row number and error in front', function () {
    $path = $this->reporter->call('storeFailedRowsCsv', ['name', 'revenue'], [
        ['row' => 3, 'data' => ['name' => 'Ann', 'revenue' => '25,5'], 'error' => 'Bad revenue'],
    ], new CsvDialect(';'));

    $lines = preg_split('/\R/', trim(Storage::disk('local')->get($path)));

    expect($path)->toStartWith('contact-imports/failed-')
        ->and($lines[0])->toEndWith('_row_number;_error;name;revenue')
        ->and($lines[1])->toBe('3;"Bad revenue";Ann;25,5');
});

it('lists at most ten ignored columns and counts the rest', function () {
    $columns = array_map(fn (int $i): string => "col{$i}", range(1, 12));

    expect($this->reporter->call('ignoredColumnsLine', []))->toBeNull()
        ->and($this->reporter->call('ignoredColumnsLine', $columns))
        ->toBe('Ignored columns (no matching field): col1, col2, col3, col4, col5, col6, col7, col8, col9, col10, … and 2 more');
});

it('offers a signed link to the failed rows file for the user who started the import', function () {
    $action = $this->reporter->call('failedRowsAction', 'contact-imports/failed-abc.csv');
    $url = $action->getUrl();

    expect($action->getLabel())->toBe('Download failed rows')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('file=failed-abc.csv')
        ->and($url)->toContain('user=7');
});

it('keeps at most 1,000 failed rows', function () {
    $failedRows = [];

    foreach (range(1, 1005) as $rowNumber) {
        $this->reporter->keep($failedRows, $rowNumber, ['name' => "N{$rowNumber}"], 'Bad.');
    }

    expect($failedRows)->toHaveCount(1000)
        ->and($failedRows[0])->toBe(['row' => 1, 'data' => ['name' => 'N1'], 'error' => 'Bad.'])
        ->and($failedRows[999]['row'])->toBe(1000);
});

it('says how many failed rows are not in the failed rows file', function (int $failed, int $kept, ?string $line) {
    expect($this->reporter->call('failedRowsNotListedLine', $failed, $kept))->toBe($line);
})->with([
    'all listed' => [1000, 1000, null],
    'fewer failed than kept' => [3, 3, null],
    'one not listed' => [1001, 1000, 'Only the first 1,000 failed rows are in the failed rows file. Failed rows not listed: 1.'],
    'thousands not listed' => [21500, 1000, 'Only the first 1,000 failed rows are in the failed rows file. Failed rows not listed: 20,500.'],
]);
