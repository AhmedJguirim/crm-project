<?php

use Symfony\Component\Finder\Finder;

/**
 * Filament calls a closure passed to an `assert…Set()` method and asserts only when it returns an array, so a closure
 * typed `: bool` asserts nothing. This test refuses such a closure in the tests.
 */
function stateAssertionClosurePattern(): string
{
    return '/assert\w*Set\(\s*(static\s+)?(fn|function)\s*\([^)]*\)\s*(use\s*\([^)]*\)\s*)?:\s*bool\b/';
}

/**
 * @return array<int, string> "relative/path.php:line" for every hit
 */
function stateAssertionClosureHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__))->name('*.php') as $file) {
        if ($file->getFilename() === 'StateAssertionClosuresTest.php') {
            continue;
        }

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match(stateAssertionClosurePattern(), $line) === 1) {
                $hits[] = 'tests/'.str_replace('\\', '/', $file->getRelativePathname()).':'.($index + 1);
            }
        }
    }

    return $hits;
}

test('no test passes a bool closure to an assert…Set() method', function () {
    $this->assertSame([], stateAssertionClosureHits(), 'A closure passed to an assert…Set() method must assert inside and return an array; Filament ignores a bool.');
});

test('the bool closure pattern matches bool closures only', function (string $line, bool $matches) {
    expect(preg_match(stateAssertionClosurePattern(), $line) === 1)->toBe($matches);
})->with([
    'arrow function' => ['->assertActionDataSet(fn (array $state): bool => true)', true],
    'closure with use' => ['->assertTableActionDataSet(function (array $s) use ($x): bool {', true],
    'static arrow function' => ['->assertSchemaStateSet(static fn ($s): bool => true)', true],
    'array closure' => ['->assertActionDataSet(fn (array $state): array => [])', false],
    'plain assertSet' => ["->assertSet('a', 1)", false],
]);
