<?php

use Symfony\Component\Finder\Finder;

/**
 * Every Filament resource must refuse a `{record}` URL segment that is not a readable id with a 404 (BUG-78), which
 * the ResolvesReadableRecordKeys trait does. The arch tests do not boot the app, so the resource files are read as text.
 *
 * @return array{scanned: int, offenders: array<int, string>}
 */
function resourceRecordKeysScan(): array
{
    $scanned = 0;
    $offenders = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Filament/Resources')->depth(1)->name('*Resource.php') as $file) {
        $scanned++;

        if (! str_contains($file->getContents(), 'use ResolvesReadableRecordKeys;')) {
            $offenders[] = 'app/Filament/Resources/'.str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    return ['scanned' => $scanned, 'offenders' => $offenders];
}

it('scans every Filament resource', function () {
    expect(resourceRecordKeysScan()['scanned'])->toBeGreaterThanOrEqual(12);
});

it('has every Filament resource use ResolvesReadableRecordKeys', function () {
    $offenders = resourceRecordKeysScan()['offenders'];

    expect($offenders)->toBe([], 'These resources must "use ResolvesReadableRecordKeys;": '.implode(', ', $offenders));
});
