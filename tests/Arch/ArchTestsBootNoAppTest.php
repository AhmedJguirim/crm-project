<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<int, int> line numbers of calls to a Laravel path helper, which needs a booted application
 */
function archLaravelPathHelperHits(string $code): array
{
    $helpers = ['app_path', 'base_path', 'config_path', 'database_path', 'lang_path', 'public_path', 'resource_path', 'storage_path'];
    $tokens = PhpToken::tokenize($code);
    $count = count($tokens);
    $hits = [];

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_STRING) || ! in_array($tokens[$i]->text, $helpers, true)) {
            continue;
        }

        $k = $i + 1;

        while ($k < $count && $tokens[$k]->is(T_WHITESPACE)) {
            $k++;
        }

        if ($k < $count && $tokens[$k]->text === '(') {
            $hits[] = $tokens[$i]->line;
        }
    }

    return $hits;
}

test('arch tests build their paths without a booted application', function () {
    $hits = [];

    foreach ((new Finder)->files()->in(__DIR__)->name('*.php') as $file) {
        foreach (archLaravelPathHelperHits($file->getContents()) as $line) {
            $hits[] = 'tests/Arch/'.$file->getRelativePathname().':'.$line;
        }
    }

    sort($hits);

    expect($hits)->toBe([]);
});

test('the path helper scanner finds a call and ignores a string', function () {
    expect(archLaravelPathHelperHits("<?php foo(app_path('x'));"))->toBe([1])
        ->and(archLaravelPathHelperHits("<?php \$s = 'app_path()';"))->toBe([])
        ->and(archLaravelPathHelperHits("<?php\n\$a = 1;\n\$b = base_path ('x');"))->toBe([3]);
});
