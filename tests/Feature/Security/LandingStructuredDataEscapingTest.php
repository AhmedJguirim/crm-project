<?php

use Illuminate\Support\Facades\Blade;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->withoutVite();
});

test('a description cannot break out of the JSON-LD script', function (string $payload) {
    $html = Blade::render('<x-layouts::landing :description="$description">x</x-layouts::landing>', ['description' => $payload]);

    expect(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches))->toBe(1);

    expect($matches[1])->not->toContain('<')->not->toContain("'");

    $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    expect($data['description'])->toBe($payload);

    expect(substr_count($html, '</script>'))->toBe(substr_count($html, '<script'));
})->with(array_merge(
    SecurityPayloads::xss(),
    [
        'script breakout' => ['</script><script>alert(1)</script>'],
        'quotes and ampersand' => ['what you\'re owed & "more"'],
    ],
));
