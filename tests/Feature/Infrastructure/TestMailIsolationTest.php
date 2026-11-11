<?php

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;

test('the test configuration is the array mailer and sync queues', function () {
    expect(config('mail.default'))->toBe('array')
        ->and(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class)
        ->and(config('services.resend.key'))->toBeNull()
        ->and(config('resend.api_key'))->toBeNull()
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('queue.long_running_connection'))->toBe('sync');
});

test('a mail sent in a test lands in the array transport', function () {
    Mail::raw('ping', fn ($message) => $message->to('probe@example.com'));

    $messages = Mail::mailer()->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('probe@example.com');
});

test('phpunit.xml forces the safe values', function () {
    $xml = simplexml_load_file(base_path('phpunit.xml'));
    $forced = [];

    foreach ($xml->php->env as $env) {
        $forced[(string) $env['name']] = [(string) $env['value'], (string) $env['force']];
    }

    expect($forced['MAIL_MAILER'])->toBe(['array', 'true'])
        ->and($forced['RESEND_API_KEY'])->toBe(['(null)', 'true'])
        ->and($forced['QUEUE_CONNECTION'])->toBe(['sync', 'true'])
        ->and($forced['QUEUE_LONG_RUNNING_CONNECTION'])->toBe(['sync', 'true']);
});

test('the base test case refuses to run with a real mailer, a Resend key or a real queue', function (callable $unsafe) {
    $unsafe();

    $refuse = new ReflectionMethod($this, 'refuseRealMailOrQueue');

    expect(fn () => $refuse->invoke($this))->toThrow(RuntimeException::class, 'Refusing to run the tests');
})->with([
    'a real mailer' => [fn () => config(['mail.default' => 'resend'])],
    'a services Resend key' => [fn () => config(['services.resend.key' => 're_live_key'])],
    'a Resend package key' => [fn () => config(['resend.api_key' => 're_live_key'])],
    'a redis queue' => [fn () => config(['queue.default' => 'redis'])],
    'a redis long running queue' => [fn () => config(['queue.long_running_connection' => 'redis-long'])],
]);

test('the base test case refuses a cached config', function () {
    app()->instance('config_loaded_from_cache', true);

    $refuse = new ReflectionMethod($this, 'refuseRealMailOrQueue');

    expect(fn () => $refuse->invoke($this))->toThrow(RuntimeException::class, 'Refusing to run the tests');
});

test('the base test case accepts the safe configuration', function () {
    $refuse = new ReflectionMethod($this, 'refuseRealMailOrQueue');

    expect($refuse->invoke($this))->toBeNull();
});
