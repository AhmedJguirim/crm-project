<?php

use Symfony\Component\Finder\Finder;

/**
 * The files of the application source that name the DealStage enum, as paths relative to app/ (removed in P2 slice 3: the list is empty).
 *
 * @return array<int, string>
 */
function dealStageEnumFiles(): array
{
    $files = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        if (preg_match('/\bDealStage\b/', $file->getContents()) === 1) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    sort($files);

    return $files;
}

test('the DealStage enum is gone and nothing names it', function () {
    expect(dealStageEnumFiles())->toBe([], 'DealStage was removed (P2 slice 3): use pipeline stages.')
        ->and(file_exists(dirname(__DIR__, 2).'/app/Enums/DealStage.php'))->toBeFalse()
        ->and(enum_exists('App\\Enums\\DealStage', false))->toBeFalse();
});
