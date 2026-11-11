<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

function refuseNonTestingDatabaseForTest(object $test): mixed
{
    return (new ReflectionMethod($test, 'refuseNonTestingDatabase'))->invoke($test);
}

test('the base test case accepts the testing database', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.database'))->toMatch('/^srw-crm-test(_test_\d+)?\z/')
        ->and(refuseNonTestingDatabaseForTest($this))->toBeNull();
});

test('phpunit.xml names the same testing database as the guard', function () {
    $xml = simplexml_load_file(base_path('phpunit.xml'));
    $values = [];

    foreach ($xml->php->env as $env) {
        $values[(string) $env['name']] = (string) $env['value'];
    }

    expect($values['DB_CONNECTION'])->toBe('pgsql')
        ->and($values['DB_DATABASE'])->toBe(TestCase::TESTING_DATABASE)
        ->and(TestCase::TESTING_DATABASE)->toBe('srw-crm-test');
});

test('the base test case refuses a database that is not the testing one', function (string $key, string $value, ?string $shown) {
    $shown ??= 'sqlite/'.config('database.connections.pgsql.database');
    $original = config($key);
    config([$key => $value]);

    $message = "Refusing to run the tests: the database '{$shown}' is not the testing database 'srw-crm-test'. Run php artisan config:clear and check .env.testing and phpunit.xml.";

    try {
        expect(fn () => refuseNonTestingDatabaseForTest($this))->toThrow(RuntimeException::class, $message);
    } finally {
        config([$key => $original]);
    }
})->with([
    'the dev database' => ['database.connections.pgsql.database', 'srw-crm', 'pgsql/srw-crm'],
    'a name that only starts like it' => ['database.connections.pgsql.database', 'srw-crm-test-copy', 'pgsql/srw-crm-test-copy'],
    'a name that only ends like it' => ['database.connections.pgsql.database', 'prod-srw-crm-test', 'pgsql/prod-srw-crm-test'],
    'a worker suffix without a number' => ['database.connections.pgsql.database', 'srw-crm-test_test_', 'pgsql/srw-crm-test_test_'],
    'a worker suffix that is not a number' => ['database.connections.pgsql.database', 'srw-crm-test_test_x', 'pgsql/srw-crm-test_test_x'],
    'a trailing newline' => ['database.connections.pgsql.database', "srw-crm-test\n", "pgsql/srw-crm-test\n"],
    'the framework default name' => ['database.connections.pgsql.database', 'laravel', 'pgsql/laravel'],
    'another default connection' => ['database.default', 'sqlite', null],
]);

test('the base test case accepts a parallel worker database', function () {
    config(['database.connections.pgsql.database' => 'srw-crm-test_test_3']);

    expect(refuseNonTestingDatabaseForTest($this))->toBeNull();
});

test('the guard stops a run pointed at another database before RefreshDatabase touches it', function () {
    $result = Process::path(base_path())
        ->env([
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'srw-crm-guard-probe',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_URL' => '',
        ])
        ->run(['php', 'vendor/bin/pest', 'tests/Feature/Infrastructure/TestMailIsolationTest.php', '--filter=accepts the safe configuration']);

    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain("Refusing to run the tests: the database 'pgsql/srw-crm-guard-probe' is not the testing database 'srw-crm-test'.")
        ->and($output)->not->toContain('SQLSTATE');
});

test('the guard stops a run with a cached config before RefreshDatabase touches the database', function () {
    $config = config()->all();
    $config['database']['connections']['pgsql']['host'] = '127.0.0.1';
    $config['database']['connections']['pgsql']['port'] = '1';
    $config['database']['connections']['pgsql']['url'] = null;
    $config['database']['connections']['pgsql']['database'] = TestCase::TESTING_DATABASE;

    $directory = sys_get_temp_dir().'/crm-cached-config-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $cache = $directory.'/config.php';
    file_put_contents($cache, '<?php return '.var_export($config, true).';');

    try {
        $result = Process::path(base_path())
            ->env([
                'APP_CONFIG_CACHE' => $cache,
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '1',
                'DB_URL' => '',
            ])
            ->run(['php', 'vendor/bin/pest', 'tests/Feature/Infrastructure/TestMailIsolationTest.php', '--filter=accepts the safe configuration']);
    } finally {
        @unlink($cache);
        @rmdir($directory);
    }

    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain('Refusing to run the tests: they could reach a real mailer or queue.')
        ->and($output)->not->toContain('SQLSTATE')
        ->and(file_exists($cache))->toBeFalse()
        ->and(is_dir($directory))->toBeFalse();
});
