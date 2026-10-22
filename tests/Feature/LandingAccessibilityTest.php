<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

test('the hero image is preloaded for desktop only, with the same candidates as the hero source', function () {
    $content = $this->get('/')->assertOk()->getContent();
    $head = substr($content, 0, strpos($content, '</head>'));

    expect(substr_count($head, 'rel="preload" as="image"'))->toBe(1)
        ->and(substr_count($content, 'rel="preload" as="image"'))->toBe(1);

    preg_match('/<link rel="preload" as="image"[^>]*>/', $head, $link);
    preg_match('/<source\b[^>]*>/', $content, $source);

    preg_match('/\simagesrcset="([^"]*)"/', $link[0], $imagesrcset);
    preg_match('/\simagesizes="([^"]*)"/', $link[0], $imagesizes);
    preg_match('/\ssrcset="([^"]*)"/', $source[0], $srcset);
    preg_match('/\ssizes="([^"]*)"/', $source[0], $sizes);

    expect($link[0])->toContain('media="(min-width: 64rem)"')
        ->toContain('fetchpriority="high"')
        ->toContain(' href="'.asset('images/landing/board-720.webp').'"')
        ->and($imagesrcset[1])->not->toBeEmpty()->toBe($srcset[1])
        ->and($imagesizes[1])->not->toBeEmpty()->toBe($sizes[1]);
});

test('there is one font preload and it matches the font face', function () {
    $content = $this->get('/')->assertOk()->getContent();
    $css = file_get_contents(resource_path('css/landing.css'));

    preg_match('/<link rel="preload" href="([^"]*)" as="font"/', $content, $link);

    expect(substr_count($content, 'as="font"'))->toBe(1)
        ->and($link[1])->toBe('/fonts/landing/inter-latin-wght-normal.woff2')
        ->and($css)->toContain("url('".$link[1]."')")
        ->and(is_file(public_path($link[1])))->toBeTrue();
});

test('the landing css only scans the landing views', function () {
    $css = file_get_contents(resource_path('css/landing.css'));

    expect(str_starts_with($css, "@import 'tailwindcss' source(none);"))->toBeTrue()
        ->and($css)->toContain("@source '../views/landing.blade.php';")
        ->toContain("@source '../views/layouts/landing.blade.php';")
        ->toContain("@source '../views/components/landing/**/*.blade.php';");
});

test('reduced motion is honoured by a css safety net and by every animated element', function () {
    $css = file_get_contents(resource_path('css/landing.css'));

    expect($css)->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('animation-duration: 0.01ms !important');

    $content = $this->get('/')->assertOk()->getContent();
    preg_match_all('/class="([^"]*)"/', $content, $matches);

    $animated = 0;
    $lifted = 0;

    foreach ($matches[1] as $class) {
        if (preg_match('/(^|\s)(\S+:)?animate-(?!none)/', $class)) {
            $animated++;
            expect($class)->toContain('motion-reduce:animate-none');
        }

        if (str_contains($class, 'hover:-translate-y-')) {
            $lifted++;
            expect($class)->toContain('motion-reduce:hover:translate-y-0');
        }
    }

    expect($animated)->toBeGreaterThanOrEqual(1)
        ->and($lifted)->toBeGreaterThanOrEqual(1);
});

test('the text on the blue surfaces uses the AA-safe classes', function (string $section, string $forbidden, string $required) {
    $content = $this->get('/')->assertOk()->getContent();
    $start = strpos($content, '<section id="'.$section.'"');
    expect($start)->not->toBeFalse();
    $html = substr($content, $start, strpos($content, '</section>', $start) - $start);

    expect($html)->not->toContain($forbidden)->toContain($required);
})->with([
    'facts 75' => ['facts', 'text-white/75', 'from-primary-800 via-primary-700 to-primary-900'],
    'facts 80' => ['facts', 'text-white/80', 'text-white/90'],
    'facts gradient' => ['facts', 'via-primary-600', 'from-primary-800 via-primary-700 to-primary-900'],
    'cta 80' => ['get-started', 'text-white/80', 'from-primary-800 via-primary-700 to-primary-900'],
    'cta gradient' => ['get-started', 'via-primary-600', 'text-white/90'],
    'hero button' => ['top', 'bg-primary-500 px-4 py-4', 'bg-primary-600 px-4 py-4'],
    'hero button hover' => ['top', 'hover:bg-primary-400', 'hover:bg-primary-700'],
]);

test('the counters always expose their real value to screen readers', function (bool $loggedIn) {
    if ($loggedIn) {
        $this->actingAs(User::factory()->withPersonalOrganization()->create());
    }

    $content = $this->get('/')->assertOk()->getContent();

    preg_match_all('/<div data-counter data-target="(\d+)"[^>]*>/', $content, $tags);
    preg_match_all('/<div data-counter data-target="(\d+)"[^>]*aria-hidden="true">\s*(\d+)\s*<\/div>\s*<span class="sr-only">(\d+)<\/span>/', $content, $matches, PREG_SET_ORDER);

    expect($tags[0])->toHaveCount(4)
        ->and($matches)->toHaveCount(4)
        ->and(array_map(fn (array $match) => [$match[1], $match[2], $match[3]], $matches))
        ->toBe([['6', '6', '6'], ['4', '4', '4'], ['5', '5', '5'], ['6', '6', '6']]);
})->with([
    'guest' => false,
    'logged-in user' => true,
]);
