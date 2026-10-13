<?php

use Symfony\Component\Finder\Finder;

/**
 * Filament adds a column aggregate (`->counts()`, `->exists()`, `->avg()`, `->sum()`, `->min()`, `->max()` with a
 * relationship) to the bulk-action query twice, so a sort on that column crashes with an ambiguous ORDER BY
 * (BUG-38). The aggregate belongs on the table query with `modifyQueryUsing(withCount(...))`.
 */
function tableAggregatePattern(): string
{
    return '/->counts\(\s*[\'"\[]|->exists\(\s*[\'"\[]|->(avg|sum|min|max)\(\s*[\'"][^\'"]*[\'"]\s*,/';
}

/**
 * @return array<int, string> "relative/path.php:line" for every hit
 */
function tableAggregateHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Filament')->name('*.php') as $file) {
        $path = 'app/Filament/'.str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match(tableAggregatePattern(), $line) === 1) {
                $hits[] = $path.':'.($index + 1);
            }
        }
    }

    return $hits;
}

test('no Filament file uses a column aggregate', function () {
    $this->assertSame([], tableAggregateHits(), "Table column aggregates are selected twice by Filament's bulk-action query and crash a sort on them (BUG-38). Put the aggregate on the table query with modifyQueryUsing(withCount(...)) instead.");
});

test('the column aggregate pattern matches what it should', function (string $source, bool $matches) {
    expect(preg_match(tableAggregatePattern(), $source) === 1)->toBe($matches);
})->with([
    'counts' => ["->counts('contacts')", true],
    'counts several' => ["->counts(['contacts', 'deals'])", true],
    'exists' => ["->exists('contacts')", true],
    'sum' => ["->sum('deals', 'value')", true],
    'avg' => ["->avg('deals', 'value')", true],
    'withCount' => ["->withCount('contacts')", false],
    'the rule engine counts' => ['$this->counts()->count($rules)', false],
    'exists without argument' => ['->exists()', false],
    'sum of a column' => ["->sum('amount')", false],
    'max of a column' => ["->max('order')", false],
]);
