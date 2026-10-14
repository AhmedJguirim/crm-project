<?php

use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Tables\Contracts\HasTable;
use Symfony\Component\Finder\Finder;

/**
 * @return array<int, class-string> every instantiable class of app/ that implements Filament's HasTable contract
 */
function tableSearchHostClasses(): array
{
    $classes = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInstantiable() && $reflection->implementsInterface(HasTable::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('every table host uses the table search sanitizer', function () {
    $hosts = tableSearchHostClasses();
    $missing = array_values(array_filter($hosts, fn (string $class): bool => ! in_array(SanitizesTableSearch::class, class_uses_recursive($class), true)));

    expect($missing)->toBe([], 'Every table host must use '.SanitizesTableSearch::class.' (BUG-44): '.implode(', ', $missing));
    expect(count($hosts))->toBeGreaterThanOrEqual(16);
});
