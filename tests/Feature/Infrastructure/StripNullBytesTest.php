<?php

use App\Http\Middleware\StripNullBytes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('the middleware is in the global stack', function () {
    expect(app(Kernel::class)->hasMiddleware(StripNullBytes::class))->toBeTrue();
});

test('json and form requests lose their NUL bytes', function () {
    Route::post('/test-null-bytes', fn (Request $request) => response()->json($request->all()));

    $json = $this->postJson('/test-null-bytes', ['name' => "Ann\0Lee", 'nested' => ['v' => "a\0b"], 'n' => 5, 'empty' => null]);
    $form = $this->post('/test-null-bytes', ['name' => "Ann\0Lee"]);

    expect($json->getContent())->toBe('{"name":"AnnLee","nested":{"v":"ab"},"n":5,"empty":null}')
        ->and($form->getContent())->toBe('{"name":"AnnLee"}');
});

test('a query string loses its NUL bytes', function () {
    Route::get('/test-null-bytes', fn (Request $request) => response()->json($request->query()));

    expect($this->get('/test-null-bytes?q=s%00t')->getContent())->toBe('{"q":"st"}');
});

test('a livewire update payload is cleaned but its snapshot is not touched', function () {
    Route::post('/test-null-bytes', fn (Request $request) => response()->json($request->all()));
    $snapshot = json_encode(['data' => ['name' => "keep\0me"], 'checksum' => 'abc']);

    $payload = $this->postJson('/test-null-bytes', ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['mountedActions.0.data.text_value' => "x\0y"],
        'calls' => [['method' => 'save', 'params' => ["a\0b", ['k' => "c\0d"]]]],
    ]]])->json('components.0');

    expect($payload['updates']['mountedActions.0.data.text_value'])->toBe('xy')
        ->and($payload['calls'][0]['params'])->toBe(['ab', ['k' => 'cd']])
        ->and($payload['snapshot'])->toBe($snapshot);
});
