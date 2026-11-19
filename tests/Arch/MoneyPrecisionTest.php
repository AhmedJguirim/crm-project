<?php

use Symfony\Component\Finder\Finder;

/**
 * Money shows exactly 2 decimals in every currency, TND included (ICU gives TND 3 by default) (BUG-76, BUG-88).
 * `Number::currency()` must get `precision: 2` and Filament's `->money()` must get `decimalPlaces: 2`.
 *
 * @return array<int, string> "path:line → call" for every money format without 2 decimals
 */
function moneyPrecisionHitsInCode(string $code, string $path): array
{
    preg_match_all('/(Number::currency|->\s*money)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
    $hits = [];

    foreach ($matches[0] as $index => [$text, $offset]) {
        $start = $offset + strlen($text);
        $position = $start;
        $depth = 1;

        while ($position < strlen($code) && $depth > 0) {
            $depth += match ($code[$position]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $position++;
        }

        $arguments = substr($code, $start, $position - $start - 1);
        $isCurrency = $matches[1][$index][0] === 'Number::currency';
        $rule = $isCurrency ? '/\bprecision:\s*2\b/' : '/\bdecimalPlaces:\s*2\b/';

        if (preg_match($rule, $arguments) !== 1) {
            $hits[] = $path.':'.(substr_count($code, "\n", 0, $offset) + 1).' → '.($isCurrency ? 'Number::currency' : '->money');
        }
    }

    return $hits;
}

/**
 * @return array<int, string> "relative/path:line → call" for every hit under app/ and resources/views/
 */
function moneyPrecisionHits(): array
{
    $root = dirname(__DIR__, 2);
    $hits = [];

    foreach (['app', 'resources/views'] as $directory) {
        foreach ((new Finder)->files()->in($root.'/'.$directory)->name('*.php') as $file) {
            $path = $directory.'/'.str_replace('\\', '/', $file->getRelativePathname());

            array_push($hits, ...moneyPrecisionHitsInCode($file->getContents(), $path));
        }
    }

    return $hits;
}

test('no money format in the app lacks 2 decimals', function () {
    $this->assertSame([], moneyPrecisionHits(), "Money must show 2 decimals in every currency, TND included (BUG-76, BUG-88). Pass precision: 2 to Number::currency() and decimalPlaces: 2 to ->money():\n".implode("\n", moneyPrecisionHits()));
});

test('the money precision scanner flags what it should', function (string $code, int $count) {
    expect(moneyPrecisionHitsInCode($code, 'x.php'))->toHaveCount($count);
})->with([
    'no precision' => ['Number::currency($v, $c)', 1],
    'precision 2' => ['Number::currency($v, $c, precision: 2)', 0],
    'precision 3' => ['Number::currency($v, $c, precision: 3)', 1],
    'multi line' => ["Number::currency(\n    (float) \$d->value,\n    \$c,\n    precision: 2,\n)", 0],
    'nested call without precision' => ['Number::currency(max(0, $v), $c)', 1],
    'money closure without decimals' => ['->money(fn (): string => $org->currencyCode())', 1],
    'money with decimals' => ['->money(fn (Invoice $r): Currency => $r->currency, decimalPlaces: 2)', 0],
    'money without argument' => ['->money()', 1],
    'blade output' => ['{{ Number::currency($total, $currency, precision: 2) }}', 0],
    'a compliant call after a violating one' => ['Number::currency($v, $c); Number::currency($v, $c, precision: 2);', 1],
    'number format' => ['Number::format($v)', 0],
]);

test('the money precision scanner reports the line and the call', function () {
    expect(moneyPrecisionHitsInCode("<?php\n\$a = 1;\nNumber::currency(\$v, \$c);", 'x.php'))->toBe(['x.php:3 → Number::currency']);
});
