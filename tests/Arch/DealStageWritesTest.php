<?php

use Symfony\Component\Finder\Finder;

/**
 * Only DealStageMover decides a deal's status and its won / lost dates. This test scans the application source
 * for hand-written writes of those attributes and fails on any match outside the class.
 *
 * @return array<string, array{
 *     pattern: string,
 *     except: array<int, string>
 * }>
 */
function dealStageWritePatterns(): array
{
    return [
        'a won_at or lost_at array key' => [
            'pattern' => '/[\'"](won_at|lost_at)[\'"]\s*=>/',
            'except' => ['Services/Deals/DealStageMover.php', 'Models/Deal.php'],
        ],
        'an assignment to won_at or lost_at' => [
            'pattern' => '/\$\w+\[[\'"](won_at|lost_at)[\'"]\]\s*(\?\?)?=[^=>]/',
            'except' => ['Services/Deals/DealStageMover.php'],
        ],
        'a status array key set to a DealStatus' => [
            'pattern' => '/[\'"]status[\'"]\s*=>\s*DealStatus::(Open|Won|Lost)\b/',
            'except' => ['Services/Deals/DealStageMover.php'],
        ],
        'an assignment of a DealStatus to status' => [
            'pattern' => '/\$\w+\[[\'"]status[\'"]\]\s*=\s*DealStatus::/',
            'except' => ['Services/Deals/DealStageMover.php'],
        ],
    ];
}

/**
 * @return array<int, string> "relative/path.php:line (what)" for every hit
 */
function dealStageWriteHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            foreach (dealStageWritePatterns() as $name => $rule) {
                if (in_array($relative, $rule['except'], true)) {
                    continue;
                }

                if (preg_match($rule['pattern'], $line) === 1) {
                    $hits[] = "{$relative}:".($index + 1)." ({$name})";
                }
            }
        }
    }

    return $hits;
}

test('only DealStageMover writes the status and the won and lost dates of a deal', function () {
    expect(dealStageWriteHits())->toBe([]);
});
