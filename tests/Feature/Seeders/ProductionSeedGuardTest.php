<?php

use App\Models\Contact;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ItConsultingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('db:seed is prohibited in production, on a real boot in a subprocess', function () {
    $result = Process::path(base_path())
        ->env([
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ])
        ->run(['php', 'artisan', 'db:seed', '--force', '--no-interaction']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output().$result->errorOutput())->toContain('This command is prohibited from running in this environment.');
});

test('db:seed is not prohibited outside production', function () {
    $result = Process::path(base_path())
        ->env([
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ])
        ->run(['php', 'artisan', 'db:seed', '--force', '--no-interaction']);

    expect($result->output().$result->errorOutput())->not->toContain('This command is prohibited from running in this environment.');
});

test('the dev seeder refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(ItConsultingSeeder::class)->run())->toThrow(
        RuntimeException::class,
        'The dev seed creates logins with a known password and never runs in production. Use php artisan demo:create-account for demo data.',
    );

    expect(User::query()->where('email', 'like', '%@example.com')->count())->toBe(0);
});

test('demo:create-account still works in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    Http::fake();

    $exitCode = $this->artisan('demo:create-account', ['name' => 'Jane Demo', 'email' => 'jane@acme.test'])
        ->expectsQuestion('Password', 'Demo-Passw0rd!2026')
        ->expectsQuestion('Confirm the password', 'Demo-Passw0rd!2026')
        ->run();

    expect($exitCode)->toBe(0);

    $organization = User::query()->where('email', 'jane@acme.test')->sole()->personalOrganization();

    expect(app(TenantContext::class)->run($organization->id, fn (): int => Contact::query()->count()))->toBe(20)
        ->and(User::query()->where('email', 'like', '%@example.com')->count())->toBe(0);
});
