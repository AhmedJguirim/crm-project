<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const ENV_CHECK_COMMITTED_KEY = 'base64:Y29tbWl0dGVkLWtleS1jb21taXR0ZWQta2V5LTAwMDA=';
const ENV_CHECK_COMMITTED_PASSWORD = 'committed-db-password';

/**
 * @return array<int, string>
 */
function envCheckCreatedDirs(?string $add = null): array
{
    static $dirs = [];

    if ($add !== null) {
        $dirs[] = $add;
    }

    return $dirs;
}

/**
 * @return array<string, string>
 */
function envCheckSafeValues(): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:cHJvZHVjdGlvbi1rZXktcHJvZHVjdGlvbi1rZXktMDA=',
        'APP_URL' => 'https://crm.example.com',
        'DB_PASSWORD' => 'prod-db-password-2026',
        'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_ENCRYPT' => 'true',
        'LOG_LEVEL' => 'warning',
        'LOG_STACK' => 'daily',
        'RESEND_WEBHOOK_SECRET' => 'whsec_prodsecret2026',
        'REDIS_PASSWORD' => 'prod-redis-password',
    ];
}

/**
 * @param  array<string, string|null>  $changes  a null value removes the line
 * @param  array<int, string>  $extraLines  appended after the key lines, verbatim
 */
function envCheckDirectory(array $changes = [], array $extraLines = [], int $mode = 0600, bool $withExample = true): string
{
    $dir = sys_get_temp_dir().'/env-check-'.Str::random(8);
    File::ensureDirectoryExists($dir);
    envCheckCreatedDirs($dir);

    $lines = [];
    foreach (array_merge(envCheckSafeValues(), $changes) as $key => $value) {
        if ($value !== null) {
            $lines[] = $key.'='.$value;
        }
    }

    file_put_contents($dir.'/.env', implode("\n", array_merge($lines, $extraLines))."\n");
    chmod($dir.'/.env', $mode);

    if ($withExample) {
        file_put_contents($dir.'/.env.example', 'APP_KEY='.ENV_CHECK_COMMITTED_KEY."\nDB_PASSWORD=".ENV_CHECK_COMMITTED_PASSWORD."\n");
    }

    return $dir;
}

/**
 * @return array{code: int, lines: array<int, string>, output: string}
 */
function envCheckRun(string $dir): array
{
    $result = Process::run(['bash', base_path('check-production-env.sh'), $dir]);
    $output = $result->output();

    return [
        'code' => $result->exitCode(),
        'lines' => explode("\n", trim($output)),
        'output' => $output,
    ];
}

function envCheckGit(string $dir, string ...$arguments): void
{
    Process::path($dir)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...$arguments])->throw();
}

afterEach(function () {
    foreach (envCheckCreatedDirs() as $dir) {
        File::deleteDirectory($dir);
    }
});

const ENV_CHECK_LINES = [
    'debug' => 'FAIL  APP_DEBUG is on: error pages show stack traces and environment values. Set APP_DEBUG=false.',
    'key_empty' => 'FAIL  APP_KEY is empty. Run: php artisan key:generate',
    'key_committed' => 'FAIL  APP_KEY is a key committed in .env.example (public repository). Generate a new one with php artisan key:generate and do not keep the old one in APP_PREVIOUS_KEYS.',
    'previous_committed' => 'FAIL  APP_PREVIOUS_KEYS contains a key committed in .env.example (public repository). Remove it.',
    'password_committed' => 'FAIL  DB_PASSWORD is the password committed in .env.example (public repository). Change the database password.',
    'password_empty' => 'WARN  DB_PASSWORD is empty: make sure the database only accepts local connections.',
    'secure_cookie' => 'FAIL  SESSION_SECURE_COOKIE is not true: the session cookie is also sent over plain http. Set SESSION_SECURE_COOKIE=true.',
    'encrypt' => 'WARN  SESSION_ENCRYPT is not true: session data is stored unencrypted. Set SESSION_ENCRYPT=true (everyone is logged out once).',
    'log_level' => 'WARN  LOG_LEVEL is debug: logs grow fast and can hold request details. Set LOG_LEVEL=warning.',
    'log_single' => 'WARN  The log file is never rotated (single channel). Set LOG_STACK=daily.',
    'webhook' => 'FAIL  POST /resend/webhook accepts unsigned requests: set RESEND_WEBHOOK_SECRET (Resend dashboard), or RESEND_ROUTES=false while no webhook is used.',
    'git_history_prefix' => 'WARN  Could not read the git history of .env.example (git: ',
    'git_history_suffix' => '): keys committed earlier are not checked.',
    'redis' => 'WARN  REDIS_PASSWORD is empty: make sure Redis only listens on localhost or a private network.',
];

test('a safe production env passes with exactly two lines', function () {
    $dir = envCheckDirectory();

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(0)
        ->and($result['lines'])->toBe(["Checking {$dir}/.env", 'Production env check: no problems found.']);
});

test('a missing env file exits with 2', function () {
    $dir = sys_get_temp_dir().'/env-check-'.Str::random(8);
    File::ensureDirectoryExists($dir);
    envCheckCreatedDirs($dir);

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain("FAIL  No .env file in {$dir}.");
});

test('the directory defaults to the current directory', function () {
    $dir = envCheckDirectory(['APP_DEBUG' => 'true']);

    $result = Process::path($dir)->run(['bash', base_path('check-production-env.sh')]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Checking ./.env')
        ->and($result->output())->toContain(ENV_CHECK_LINES['debug']);
});

dataset('env check single failures', [
    'APP_ENV=local' => [['APP_ENV' => 'local'], 'FAIL  APP_ENV is "local", not "production": local mode opens the Horizon dashboard to everyone and relaxes the password rules.'],
    'APP_DEBUG=true' => [['APP_DEBUG' => 'true'], ENV_CHECK_LINES['debug']],
    'APP_DEBUG=on' => [['APP_DEBUG' => 'on'], ENV_CHECK_LINES['debug']],
    'APP_DEBUG=(true)' => [['APP_DEBUG' => '(true)'], ENV_CHECK_LINES['debug']],
    'APP_DEBUG quoted with comment' => [['APP_DEBUG' => '"1" # quoted'], ENV_CHECK_LINES['debug']],
    'APP_KEY empty' => [['APP_KEY' => ''], ENV_CHECK_LINES['key_empty']],
    'APP_KEY removed' => [['APP_KEY' => null], ENV_CHECK_LINES['key_empty']],
    'APP_KEY committed' => [['APP_KEY' => ENV_CHECK_COMMITTED_KEY], ENV_CHECK_LINES['key_committed']],
    'APP_PREVIOUS_KEYS committed' => [['APP_PREVIOUS_KEYS' => 'base64:b3RoZXI=, '.ENV_CHECK_COMMITTED_KEY], ENV_CHECK_LINES['previous_committed']],
    'DB_PASSWORD committed' => [['DB_PASSWORD' => ENV_CHECK_COMMITTED_PASSWORD], ENV_CHECK_LINES['password_committed']],
    'APP_URL http' => [['APP_URL' => 'http://crm.example.com'], 'FAIL  APP_URL does not start with https:// ("http://crm.example.com"): cookies and signed download links need the public https address.'],
    'SESSION_SECURE_COOKIE removed' => [['SESSION_SECURE_COOKIE' => null], ENV_CHECK_LINES['secure_cookie']],
    'SESSION_SECURE_COOKIE=false' => [['SESSION_SECURE_COOKIE' => 'false'], ENV_CHECK_LINES['secure_cookie']],
    'RESEND_WEBHOOK_SECRET removed' => [['RESEND_WEBHOOK_SECRET' => null], ENV_CHECK_LINES['webhook']],
    'RESEND_WEBHOOK_SECRET empty' => [['RESEND_WEBHOOK_SECRET' => ''], ENV_CHECK_LINES['webhook']],
    'RESEND_ROUTES=true explicit' => [['RESEND_ROUTES' => 'true', 'RESEND_WEBHOOK_SECRET' => null], ENV_CHECK_LINES['webhook']],
]);

it('reports exactly one failure', function (array $changes, string $line) {
    $result = envCheckRun(envCheckDirectory($changes));

    expect($result['code'])->toBe(1)
        ->and($result['lines'])->toContain($line)
        ->and(end($result['lines']))->toBe('Production env check: 1 failure(s), 0 warning(s).');
})->with('env check single failures');

test('development packages are a failure', function () {
    $dir = envCheckDirectory();
    File::ensureDirectoryExists($dir.'/vendor/fruitcake/laravel-debugbar');

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(1)
        ->and($result['lines'])->toContain('FAIL  Development packages are installed (vendor/fruitcake/laravel-debugbar). Deploy with: composer install --no-dev --optimize-autoloader')
        ->and(end($result['lines']))->toBe('Production env check: 1 failure(s), 0 warning(s).');
});

test('both development packages are listed in order', function () {
    $dir = envCheckDirectory();
    File::ensureDirectoryExists($dir.'/vendor/laravel/boost');
    File::ensureDirectoryExists($dir.'/vendor/fruitcake/laravel-debugbar');

    expect(envCheckRun($dir)['lines'])->toContain('FAIL  Development packages are installed (vendor/fruitcake/laravel-debugbar, vendor/laravel/boost). Deploy with: composer install --no-dev --optimize-autoloader');
});

dataset('env check single warnings', [
    'DB_PASSWORD removed' => [['DB_PASSWORD' => null], ENV_CHECK_LINES['password_empty']],
    'SESSION_ENCRYPT=false' => [['SESSION_ENCRYPT' => 'false'], ENV_CHECK_LINES['encrypt']],
    'LOG_LEVEL removed' => [['LOG_LEVEL' => null], ENV_CHECK_LINES['log_level']],
    'LOG_STACK removed' => [['LOG_STACK' => null], ENV_CHECK_LINES['log_single']],
    'LOG_STACK=single,stderr' => [['LOG_STACK' => 'single,stderr'], ENV_CHECK_LINES['log_single']],
    'LOG_CHANNEL=single' => [['LOG_CHANNEL' => 'single'], ENV_CHECK_LINES['log_single']],
    'REDIS_PASSWORD=null' => [['REDIS_PASSWORD' => 'null'], ENV_CHECK_LINES['redis']],
    'APP_URL local https' => [['APP_URL' => 'https://crm.test'], 'WARN  APP_URL points to a local address ("https://crm.test"): set the public address of the application.'],
]);

it('reports exactly one warning and exits 0', function (array $changes, string $line) {
    $result = envCheckRun(envCheckDirectory($changes));

    expect($result['code'])->toBe(0)
        ->and($result['lines'])->toContain($line)
        ->and(end($result['lines']))->toBe('Production env check: 0 failure(s), 1 warning(s).');
})->with('env check single warnings');

test('an env file readable by others warns with its mode', function () {
    $result = envCheckRun(envCheckDirectory(mode: 0644));

    expect($result['code'])->toBe(0)
        ->and($result['lines'])->toContain('WARN  .env is readable by other users of the server (mode 644). Run: chmod 600 .env')
        ->and(end($result['lines']))->toBe('Production env check: 0 failure(s), 1 warning(s).');
});

test('a local http address both fails and warns', function () {
    $result = envCheckRun(envCheckDirectory(['APP_URL' => null]));

    expect($result['code'])->toBe(1)
        ->and($result['lines'])->toContain('FAIL  APP_URL does not start with https:// ("http://localhost"): cookies and signed download links need the public https address.')
        ->and($result['lines'])->toContain('WARN  APP_URL points to a local address ("http://localhost"): set the public address of the application.');
});

test('local hosts are recognised whatever the port, path or user info', function (string $url) {
    expect(envCheckRun(envCheckDirectory(['APP_URL' => $url]))['output'])->toContain('WARN  APP_URL points to a local address');
})->with([
    'https://localhost:8443/app',
    'https://127.0.0.1',
    'https://0.0.0.0:80',
    'https://[::1]:8000/x',
    'https://user:pw@crm.local/',
    'https://crm.localhost',
    'HTTPS://LOCALHOST',
]);

test('a public https address does not warn', function () {
    expect(envCheckRun(envCheckDirectory(['APP_URL' => 'https://crm.test.example.com:8443/path?x=1']))['output'])->not->toContain('APP_URL');
});

test('values that mean debug off pass', function (?string $value) {
    expect(envCheckRun(envCheckDirectory(['APP_DEBUG' => $value]))['output'])->not->toContain('FAIL  APP_DEBUG');
})->with(['false', 'FALSE', '0', '(false)', '"false"', "'false'", null, 'false # prod', 'null', '', ' false ']);

test('the last occurrence of a key wins', function () {
    $dir = envCheckDirectory(['APP_DEBUG' => null], ['APP_DEBUG=true', 'export APP_DEBUG = false']);

    expect(envCheckRun($dir)['output'])->not->toContain('FAIL  APP_DEBUG');

    $dir = envCheckDirectory(['APP_DEBUG' => 'false'], ['APP_DEBUG=true']);

    expect(envCheckRun($dir)['output'])->toContain(ENV_CHECK_LINES['debug']);
});

test('windows line endings do not turn an off value into an on value', function () {
    $dir = envCheckDirectory();
    file_put_contents($dir.'/.env', str_replace("\n", "\r\n", file_get_contents($dir.'/.env')));
    chmod($dir.'/.env', 0600);

    expect(envCheckRun($dir)['lines'])->toBe(["Checking {$dir}/.env", 'Production env check: no problems found.']);
});

test('the webhook check is off when the routes are off', function (string $value) {
    $result = envCheckRun(envCheckDirectory(['RESEND_WEBHOOK_SECRET' => null, 'RESEND_ROUTES' => $value]));

    expect($result['output'])->not->toContain('/resend/webhook')
        ->and($result['code'])->toBe(0);
})->with(['false', '0', '(false)']);

test('a key that only exists in the history of the example file is caught', function () {
    $dir = envCheckDirectory(['APP_KEY' => ENV_CHECK_COMMITTED_KEY, 'DB_PASSWORD' => ENV_CHECK_COMMITTED_PASSWORD], withExample: false);
    envCheckGit($dir, 'init', '-q');
    file_put_contents($dir.'/.env.example', 'APP_KEY='.ENV_CHECK_COMMITTED_KEY."\nDB_PASSWORD=".ENV_CHECK_COMMITTED_PASSWORD."\n");
    envCheckGit($dir, 'add', '.env.example');
    envCheckGit($dir, 'commit', '-q', '-m', 'first');
    file_put_contents($dir.'/.env.example', "APP_KEY=\nDB_PASSWORD=\n");
    envCheckGit($dir, 'commit', '-q', '-am', 'second');

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(1)
        ->and($result['lines'])->toContain(ENV_CHECK_LINES['key_committed'])
        ->and($result['lines'])->toContain(ENV_CHECK_LINES['password_committed'])
        ->and($result['output'])->not->toContain('Could not read the git history');
});

test('without a git directory the history is not consulted', function () {
    $dir = envCheckDirectory(['APP_KEY' => ENV_CHECK_COMMITTED_KEY, 'DB_PASSWORD' => ENV_CHECK_COMMITTED_PASSWORD], withExample: false);
    file_put_contents($dir.'/.env.example', "APP_KEY=\nDB_PASSWORD=\n");

    expect(envCheckRun($dir)['code'])->toBe(0);
});

test('secrets are never printed', function () {
    $dir = envCheckDirectory([
        'APP_KEY' => ENV_CHECK_COMMITTED_KEY,
        'APP_PREVIOUS_KEYS' => ENV_CHECK_COMMITTED_KEY,
        'DB_PASSWORD' => ENV_CHECK_COMMITTED_PASSWORD,
        'APP_DEBUG' => 'true',
        'REDIS_PASSWORD' => 'prod-redis-password',
        'RESEND_ROUTES' => 'true',
    ]);
    File::ensureDirectoryExists($dir.'/vendor/laravel/boost');

    $output = envCheckRun($dir)['output'];

    expect($output)->toContain('FAIL')
        ->and($output)->not->toContain('Y29tbWl0dGVkLWtleS1jb21taXR0ZWQta2V5LTAwMDA')
        ->and($output)->not->toContain('committed-db-password')
        ->and($output)->not->toContain('whsec_prodsecret2026')
        ->and($output)->not->toContain('prod-redis-password')
        ->and($output)->not->toContain('prod-db-password-2026');
});

test('the env file is read as text and never executed', function () {
    $marker = sys_get_temp_dir().'/env-check-marker-'.Str::random(8);
    $dir = envCheckDirectory(['APP_NAME' => '$(touch '.$marker.')'], ['`touch '.$marker.'`', 'touch '.$marker, 'APP_URL=https://crm.example.com/$(touch '.$marker.')']);

    $result = envCheckRun($dir);

    expect(file_exists($marker))->toBeFalse()
        ->and($result['code'])->toBe(0);
});

test('an unreadable env file stops the check with exit 2', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root can read a file with mode 000.');
    }
    $dir = envCheckDirectory(mode: 0000);

    $result = envCheckRun($dir);
    chmod($dir.'/.env', 0600);

    expect($result['code'])->toBe(2)
        ->and($result['lines'])->toBe(["Checking {$dir}/.env", "FAIL  Cannot read {$dir}/.env."])
        ->and($result['output'])->not->toContain('APP_KEY is empty');
});

test('an unreadable env example warns', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root can read a file with mode 000.');
    }
    $dir = envCheckDirectory();
    chmod($dir.'/.env.example', 0000);

    $result = envCheckRun($dir);
    chmod($dir.'/.env.example', 0600);

    expect($result['code'])->toBe(0)
        ->and($result['lines'])->toContain("WARN  Cannot read {$dir}/.env.example: keys committed in it are not checked.")
        ->and(end($result['lines']))->toBe('Production env check: 0 failure(s), 1 warning(s).');
});

test('a git directory that is not a repository warns', function () {
    $dir = envCheckDirectory();
    File::ensureDirectoryExists($dir.'/.git');

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(0)
        ->and($result['lines'][1])->toStartWith(ENV_CHECK_LINES['git_history_prefix'])
        ->and($result['lines'][1])->toContain('not a git repository')
        ->and($result['lines'][1])->not->toContain('git: fatal:')
        ->and($result['lines'][1])->toEndWith(ENV_CHECK_LINES['git_history_suffix'])
        ->and(end($result['lines']))->toBe('Production env check: 0 failure(s), 1 warning(s).');
});

test('the git warning also shows when the directory is the current directory', function () {
    $dir = envCheckDirectory();
    File::ensureDirectoryExists($dir.'/.git');

    $result = Process::path($dir)->run(['bash', base_path('check-production-env.sh')]);

    expect($result->output())->toContain('Checking ./.env')
        ->and($result->output())->toContain(ENV_CHECK_LINES['git_history_prefix']);
});

test('an empty git directory inside another repository does not read the parent repository', function () {
    $parent = envCheckDirectory();
    envCheckGit($parent, 'init', '-q');
    envCheckGit($parent, 'add', '.env.example');
    envCheckGit($parent, 'commit', '-q', '-m', 'first');
    $sub = $parent.'/sub';
    File::ensureDirectoryExists($sub.'/.git');
    $lines = [];
    foreach (envCheckSafeValues() as $key => $value) {
        $lines[] = $key.'='.$value;
    }
    file_put_contents($sub.'/.env', implode("\n", $lines)."\n");
    chmod($sub.'/.env', 0600);

    $result = envCheckRun($sub);

    expect($result['lines'][1])->toStartWith(ENV_CHECK_LINES['git_history_prefix'])
        ->and(end($result['lines']))->toBe('Production env check: 0 failure(s), 1 warning(s).');
});

test('a readable repository whose history has no env example gives no warning', function () {
    $dir = envCheckDirectory(withExample: false);
    envCheckGit($dir, 'init', '-q');
    file_put_contents($dir.'/x', "x\n");
    envCheckGit($dir, 'add', 'x');
    envCheckGit($dir, 'commit', '-q', '-m', 'first');

    $result = envCheckRun($dir);

    expect($result['code'])->toBe(0)
        ->and($result['lines'])->toBe(["Checking {$dir}/.env", 'Production env check: no problems found.']);
});

test('the git warning never prints a secret', function () {
    $dir = envCheckDirectory(['APP_KEY' => ENV_CHECK_COMMITTED_KEY]);
    File::ensureDirectoryExists($dir.'/.git');

    $output = envCheckRun($dir)['output'];

    expect($output)->toContain('Could not read the git history')
        ->and($output)->not->toContain('Y29tbWl0dGVkLWtleS1jb21taXR0ZWQta2V5LTAwMDA')
        ->and($output)->not->toContain('prod-db-password-2026');
});

test('a git failure with no message gives its exit code', function () {
    $dir = envCheckDirectory();
    File::ensureDirectoryExists($dir.'/.git');
    $bin = $dir.'/fake-bin';
    File::ensureDirectoryExists($bin);
    file_put_contents($bin.'/git', "#!/bin/sh\nexit 3\n");
    chmod($bin.'/git', 0755);

    $result = Process::env(['PATH' => $bin.':'.getenv('PATH')])->run(['bash', base_path('check-production-env.sh'), $dir]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain(ENV_CHECK_LINES['git_history_prefix'].'exit code 3'.ENV_CHECK_LINES['git_history_suffix']);
});
