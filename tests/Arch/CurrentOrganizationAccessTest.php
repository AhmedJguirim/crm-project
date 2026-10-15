<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<int, int> line numbers of calls to a method or function named getTenant
 */
function archGetTenantCallLines(string $code): array
{
    $tokens = PhpToken::tokenize($code);
    $count = count($tokens);
    $hits = [];

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_STRING) || $tokens[$i]->text !== 'getTenant') {
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

test('only CurrentOrganization reads the panel tenant', function () {
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        if ($file->getRelativePathname() === 'Support/Tenancy/CurrentOrganization.php') {
            continue;
        }

        foreach (archGetTenantCallLines($file->getContents()) as $line) {
            $hits[] = 'app/'.$file->getRelativePathname().':'.$line;
        }
    }

    sort($hits);

    expect($hits)->toBe([]);
});

test('the getTenant scanner finds a call and ignores lookalikes', function () {
    expect(archGetTenantCallLines('<?php Filament::getTenant()->id;'))->toBe([1])
        ->and(archGetTenantCallLines('<?php filament()->getTenant ();'))->toBe([1])
        ->and(archGetTenantCallLines('<?php $p->getTenantModel();'))->toBe([])
        ->and(archGetTenantCallLines("<?php \$s = 'getTenant()';"))->toBe([]);
});
