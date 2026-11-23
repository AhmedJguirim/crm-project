<?php

const FILAMENT_NONCE_ATTRIBUTE = ' nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"';

/**
 * @return array<string, array{0: array{view: string, override: string, vendor: string, hash: string, count: int}}>
 */
function filamentNonceOverrides(): array
{
    return [
        'layout base' => [[
            'view' => 'filament-panels::components.layout.base',
            'override' => 'views/vendor/filament-panels/components/layout/base.blade.php',
            'vendor' => 'vendor/filament/filament/resources/views/components/layout/base.blade.php',
            'hash' => 'b95a450ddaf68af8b5c71760bcb30a295ec71e7f11fcbd2fe9fc13f25ae26968',
            'count' => 5,
        ]],
        'sidebar' => [[
            'view' => 'filament-panels::livewire.sidebar',
            'override' => 'views/vendor/filament-panels/livewire/sidebar.blade.php',
            'vendor' => 'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
            'hash' => '9d53c25551beb97b9642a04d34cd34a33805de3664bf8d17abbf1ad4b8017a6b',
            'count' => 1,
        ]],
    ];
}

it('resolves the nonce override of the Filament view', function (array $case) {
    expect(view()->getFinder()->find($case['view']))->toBe(resource_path($case['override']));
})->with(filamentNonceOverrides());

it('has the vendor file unchanged since the override was copied', function (array $case) {
    $path = str_replace('vendor/filament/filament/resources/views/', '', $case['vendor']);

    expect(hash_file('sha256', base_path($case['vendor'])))->toBe(
        $case['hash'],
        "Filament's {$path} changed. Copy vendor/filament/filament/resources/views/{$path} over resources/views/vendor/filament-panels/{$path}, re-add the SEC-70 nonce attributes, then update this hash.",
    );
})->with(filamentNonceOverrides());

it('keeps the override equal to the vendor file plus the nonce attribute and nothing else', function (array $case) {
    $override = file_get_contents(resource_path($case['override']));

    expect(substr_count($override, FILAMENT_NONCE_ATTRIBUTE))->toBe($case['count'])
        ->and(str_replace(FILAMENT_NONCE_ATTRIBUTE, '', $override))->toBe(file_get_contents(base_path($case['vendor'])));
})->with(filamentNonceOverrides());

it('has no other inline script in Filament without a nonce', function () {
    $root = base_path('vendor/filament');
    $hits = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! str_ends_with($path, '.blade.php') || ! str_contains($path, '/resources/views/')) {
            continue;
        }

        $contents = preg_replace('/@script\b.*?@endscript/s', '', file_get_contents($path));
        preg_match_all('/<script\b[^>]*>/i', $contents, $matches);

        $count = count(array_filter(
            $matches[0],
            fn (string $tag): bool => ! str_contains($tag, 'nonce') && ! str_contains($tag, 'type="application/'),
        ));

        if ($count > 0) {
            $hits[substr($path, strlen($root) + 1)] = $count;
        }
    }

    ksort($hits);

    expect($hits)->toBe(
        [
            'filament/resources/views/components/layout/base.blade.php' => 5,
            'filament/resources/views/livewire/sidebar.blade.php' => 1,
        ],
        'Filament has an inline <script> without a CSP nonce in '.implode(', ', array_keys($hits)).'. Override that view with the nonce (SEC-70).',
    );
});
