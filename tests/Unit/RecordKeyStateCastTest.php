<?php

use App\Filament\Support\StateCasts\RecordKeysStateCast;
use App\Filament\Support\StateCasts\RecordKeyStateCast;

test('RecordKeyStateCast::get() keeps real ids only', function (mixed $input, ?int $output) {
    expect((new RecordKeyStateCast)->get($input))->toBe($output)
        ->and(RecordKeyStateCast::toKey($input))->toBe($output);
})->with([
    'int' => [5, 5],
    'numeric string' => ['5', 5],
    'largest id' => ['9223372036854775807', PHP_INT_MAX],
    'overflow' => ['9223372036854775808', null],
    'letters' => ['abc', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'zero string' => ['0', null],
    'zero' => [0, null],
    'negative' => ['-1', null],
    'negative int' => [-3, null],
    'leading zero' => ['05', null],
    'leading space' => [' 5', null],
    'trailing space' => ['5 ', null],
    'trailing newline' => ["5\n", null],
    'plus sign' => ['+5', null],
    'decimal' => ['5.0', null],
    'exponent' => ['1e3', null],
    'bool' => [true, null],
    'float' => [5.5, null],
    'array' => [['5'], null],
]);

test('RecordKeyStateCast::set() keeps the raw scalar for validation', function (mixed $input, ?string $output) {
    expect((new RecordKeyStateCast)->set($input))->toBe($output);
})->with([
    'numeric string' => ['5', '5'],
    'int' => [5, '5'],
    'letters' => ['abc', 'abc'],
    'empty string' => ['', null],
    'null' => [null, null],
    'array' => [['5'], null],
]);

test('RecordKeysStateCast keeps the real ids and the raw junk on the way back', function () {
    $cast = new RecordKeysStateCast;

    expect($cast->get(['3', 'abc', 3, '', '7']))->toBe([3, 7])
        ->and($cast->get(['05', '-1', '5.5', '1e3', '0', ' 4', [6]]))->toBe([])
        ->and($cast->get('3'))->toBe([])
        ->and($cast->get(null))->toBe([])
        ->and($cast->set(['3', 'abc', '', ['x']]))->toBe(['3', 'abc'])
        ->and($cast->set(null))->toBe([]);
});
