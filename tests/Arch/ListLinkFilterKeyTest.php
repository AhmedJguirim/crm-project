<?php

use Symfony\Component\Finder\Finder;

/**
 * Filament v5 list pages bind their table filters to the query key `filters`; a link built with `?tableFilters[...]`
 * opens the list with no filter, so the number on a stat and the list behind it disagree (BUG-57).
 */
test('no link in app uses the ignored tableFilters query key', function () {
    $root = dirname(__DIR__, 2).'/app';
    $offenders = [];

    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        if (preg_match('/[?&]tableFilters\[/', $file->getContents()) === 1) {
            $offenders[] = 'app/'.$file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Link to a list with ?filters[...], not ?tableFilters[...] (BUG-57): '.implode(', ', $offenders));
});
