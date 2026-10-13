<?php

use Symfony\Component\Finder\Finder;

/**
 * Every select filter of a table must be built with RecordKeySelectFilter or OptionSelectFilter (BUG-32), which drop a
 * forged value before Filament queries with it. This test scans app/Filament for a plain `SelectFilter::make(`.
 * A fully-qualified call is not matched (not used in app code).
 */
function tableSelectFilterPattern(): string
{
    return '/(?<![\w\\\\])SelectFilter::make\(/';
}

/**
 * @return array<int, string> "relative/path.php:line" for every hit
 */
function tableSelectFilterHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Filament')->name('*.php') as $file) {
        $path = 'app/Filament/'.str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match(tableSelectFilterPattern(), $line) === 1) {
                $hits[] = $path.':'.($index + 1);
            }
        }
    }

    return $hits;
}

test('no select filter is built without a guarded filter class', function () {
    $this->assertSame([], tableSelectFilterHits(), 'Select filters must be built with App\Filament\Support\Filters\RecordKeySelectFilter or OptionSelectFilter (BUG-32).');
});

test('the select filter pattern matches what it should', function (string $source, bool $matches) {
    expect(preg_match(tableSelectFilterPattern(), $source) === 1)->toBe($matches);
})->with([
    'plain select filter' => ["SelectFilter::make('status')", true],
    'record key filter' => ["RecordKeySelectFilter::make('contact_id')", false],
    'option filter' => ["OptionSelectFilter::make('status')", false],
    'fully qualified' => ["\\Filament\\Tables\\Filters\\SelectFilter::make('x')", false],
]);
