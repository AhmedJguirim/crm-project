<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The attributes of a model, ready for a direct `DB::table()` insert: what the database stores, with timestamps.
 *
 * @return array<string, mixed>
 */
function storedRowOf(Model $model): array
{
    return [...$model->getAttributes(), 'created_at' => now(), 'updated_at' => now()];
}

/**
 * Simulates a race on a unique custom field: right after the first query that compares the custom field value with
 * `$value` (the form rule, or the import's pre-check) the competing row is inserted, so the check passed and the
 * value is taken before the save.
 *
 * @param  array<string, mixed>  $row
 */
function insertCompetingRowAfterUniquenessCheck(string $table, array $row, string $value): void
{
    $inserted = false;

    DB::listen(function (QueryExecuted $query) use (&$inserted, $table, $row, $value): void {
        if ($inserted || ! str_contains($query->sql, '->>') || ! in_array($value, $query->bindings, true)) {
            return;
        }

        $inserted = true;
        DB::table($table)->insert($row);
    });
}

/**
 * Writes an .xlsx file to the faked local disk and returns its stored path. Each entry of $extraSheets becomes
 * another worksheet after the first one.
 *
 * @param  array<int, array<int, mixed>>  $rows
 * @param  array<int, array<int, array<int, mixed>>>  $extraSheets
 */
function makeXlsx(array $rows, array $extraSheets = []): string
{
    $path = 'contact-imports/test-'.uniqid().'.xlsx';
    Storage::disk('local')->makeDirectory('contact-imports');

    $writer = SimpleExcelWriter::create(Storage::disk('local')->path($path))->noHeaderRow();
    $dateStyle = (new Style)->setFormat('dd-mm-yyyy');
    $toRow = fn (array $row): Row => new Row(array_map(
        fn (mixed $value): Cell => $value instanceof DateTimeInterface
            ? Cell::fromValue($value, $dateStyle)
            : Cell::fromValue($value),
        $row,
    ));

    foreach ($rows as $row) {
        $writer->addRow($toRow($row));
    }

    foreach ($extraSheets as $sheetRows) {
        $writer->addNewSheetAndMakeItCurrent();

        foreach ($sheetRows as $row) {
            $writer->addRow($toRow($row));
        }
    }

    $writer->close();

    return $path;
}
