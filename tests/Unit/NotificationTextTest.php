<?php

use App\Support\NotificationText;

test('the helper escapes markup and keeps quotes', function (string $input, string $output) {
    expect(NotificationText::plain($input))->toBe($output);
})->with([
    'link with style' => ['<a href="https://evil.example" style="position:fixed;inset:0">x</a>', '&lt;a href="https://evil.example" style="position:fixed;inset:0"&gt;x&lt;/a&gt;'],
    'ampersand' => ['Tom & Jerry', 'Tom &amp; Jerry'],
    'quotes' => ["O'Brien \"Q\"", "O'Brien \"Q\""],
    'already escaped' => ['&lt;b&gt;', '&amp;lt;b&amp;gt;'],
    'plain' => ['Saved', 'Saved'],
]);
