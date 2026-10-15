<?php

use Symfony\Component\Finder\Finder;

/**
 * The files of the application source that still name the DealStage enum, as paths relative to app/ (P2 slice 3 empties the list).
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

test('DealStage is only used where P2 slice 3 will remove it', function () {
    expect(dealStageEnumFiles())->toBe([
        'Enums/DealStage.php',
        'Models/Deal.php',
        'Services/Deals/DefaultPipeline.php',
    ], 'DealStage is being removed (P2 slice 3): use pipeline stages.');
});
