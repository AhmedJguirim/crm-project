<?php

use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const DEPLOY_PASSWORD = 's3cr3t pass#1';

/**
 * @return array<int, string>
 */
function deployCreatedDirs(?string $add = null): array
{
    static $dirs = [];

    if ($add !== null) {
        $dirs[] = $add;
    }

    return $dirs;
}

function deployTempDir(string $prefix): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'-'.Str::random(8);
    File::ensureDirectoryExists($dir);
    deployCreatedDirs($dir);

    return realpath($dir);
}

/**
 * @param  array<string, string|null>  $changes  a null value removes the line
 * @return array{dir: string, bin: string, backup: string, log: string}
 */
function deployHarness(array $changes = [], bool $withEnv = true): array
{
    $dir = deployTempDir('deploy-project');
    $bin = deployTempDir('deploy-bin');
    $backup = deployTempDir('deploy-backup');
    $log = $bin.'/stub.log';

    copy(base_path('deploy.sh'), $dir.'/deploy.sh');

    file_put_contents($dir.'/check-production-env.sh', <<<'SH'
#!/usr/bin/env bash
echo "env-check" >> "$STUB_LOG"
if [ -n "${STUB_ENV_OUTPUT:-}" ]; then
    echo "$STUB_ENV_OUTPUT"
fi
exit "${STUB_ENV_EXIT:-0}"
SH);

    if ($withEnv) {
        $values = array_merge([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5433',
            'DB_DATABASE' => 'crm_prod',
            'DB_USERNAME' => 'crm',
            'DB_PASSWORD' => '"'.DEPLOY_PASSWORD.'"',
        ], $changes);

        $lines = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $lines[] = $key.'='.$value;
            }
        }
        file_put_contents($dir.'/.env', implode("\n", $lines)."\n");
    }

    foreach (['php', 'git', 'composer', 'npm', 'pg_dump'] as $name) {
        $extra = match ($name) {
            'git' => "if [ \"\${1:-}\" = \"rev-parse\" ]; then echo abc1234; fi\n",
            'pg_dump' => "for arg in \"\$@\"; do case \$arg in --file=*) : > \"\${arg#--file=}\" ;; esac; done\nprintf '%s' \"\${PGPASSWORD-}\" > \"\$STUB_LOG.pgpassword\"\n",
            default => '',
        };

        $stub = "#!/usr/bin/env bash\nline=\"{$name} \$*\"\necho \"\$line\" >> \"\$STUB_LOG\"\n".$extra.
            "if [ -n \"\${STUB_FAIL:-}\" ] && [[ \"\$line\" == \"\$STUB_FAIL\"* ]]; then exit 1; fi\n".
            ($name === 'git' ? "if [ -n \"\${STUB_GIT_APPEND:-}\" ] && [ \"\${1:-}\" = \"pull\" ]; then printf '%s\\n' \"\$STUB_GIT_APPEND\" >> \"\$STUB_DEPLOY_FILE\"; fi\n" : '');
        file_put_contents($bin.'/'.$name, $stub);
        chmod($bin.'/'.$name, 0755);
    }

    return ['dir' => $dir, 'bin' => $bin, 'backup' => $backup, 'log' => $log];
}

/**
 * @param  array{dir: string, bin: string, backup: string, log: string}  $h
 * @param  array<string, string>  $env
 * @return array{code: int, output: string}
 */
function deployRun(array $h, array $env = [], ?string $path = null): array
{
    $result = Process::env(array_merge([
        'PATH' => $path ?? $h['bin'].':'.getenv('PATH'),
        'STUB_LOG' => $h['log'],
        'STUB_FAIL' => '',
        'STUB_DEPLOY_FILE' => $h['dir'].'/deploy.sh',
        'DEPLOY_BACKUP_DIR' => $h['backup'],
        'DEPLOY_BACKUP_KEEP' => '',
    ], $env))->run(['bash', $h['dir'].'/deploy.sh']);

    return ['code' => $result->exitCode(), 'output' => $result->output().$result->errorOutput()];
}

/**
 * @param  array{dir: string, bin: string, backup: string, log: string}  $h
 * @return array<int, string>
 */
function deployLog(array $h): array
{
    if (! is_file($h['log'])) {
        return [];
    }

    return array_values(array_filter(explode("\n", file_get_contents($h['log']))));
}

/**
 * @param  array<int, string>  $log
 * @return array<int, string>
 */
function deployNormalizedLog(array $log): array
{
    return array_map(fn (string $line): string => preg_replace('/crm-\d{8}T\d{6}Z-/', 'crm-<STAMP>-', $line), $log);
}

/**
 * @param  array{dir: string, bin: string, backup: string, log: string}  $h
 * @return array<int, string>
 */
function deployDumps(array $h): array
{
    $files = array_map('basename', glob($h['backup'].'/crm-*.dump') ?: []);
    sort($files);

    return $files;
}

/**
 * @param  array{dir: string, bin: string, backup: string, log: string}  $h
 * @return array<int, string>
 */
function deployHappyLog(array $h): array
{
    return [
        'php artisan down --render=errors::503 --retry=60 --refresh=15',
        'php artisan horizon:pause',
        'php artisan deploy:wait-for-jobs',
        'git rev-parse --short HEAD',
        'git pull --ff-only',
        'composer install --no-dev --optimize-autoloader --no-interaction',
        'npm ci',
        'npm run build',
        'pg_dump --format=custom --no-owner --host=db.internal --port=5433 --username=crm --dbname=crm_prod --file='.$h['backup'].'/crm-<STAMP>-abc1234.dump',
        'php artisan migrate --force',
        'php artisan optimize:clear --except=cache',
        'php artisan optimize',
        'env-check',
        'php artisan horizon:terminate',
        'php artisan up',
    ];
}

afterEach(function () {
    foreach (deployCreatedDirs() as $dir) {
        File::deleteDirectory($dir);
    }
});

test('the happy path runs every step in the exact order', function () {
    $h = deployHarness();

    $result = deployRun($h);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Deploy finished.')
        ->and($result['output'])->toContain('Database backup: '.$h['backup'].'/crm-')
        ->and(deployNormalizedLog(deployLog($h)))->toBe(deployHappyLog($h));

    foreach (deployLog($h) as $line) {
        if (str_contains($line, 'optimize:clear')) {
            expect($line)->toContain('--except=cache');
        }
        expect($line)->not->toContain('cache:clear')->not->toContain('filament:optimize');
    }

    expect(deployDumps($h))->toHaveCount(1)
        ->and(deployDumps($h)[0])->toMatch('/^crm-\d{8}T\d{6}Z-abc1234\.dump$/');
});

test('the password reaches pg_dump only through PGPASSWORD and never leaks', function () {
    $h = deployHarness();

    $result = deployRun($h);
    $dump = $h['backup'].'/'.deployDumps($h)[0];

    expect(file_get_contents($h['log'].'.pgpassword'))->toBe(DEPLOY_PASSWORD)
        ->and($result['output'])->not->toContain('s3cr3t')
        ->and(file_get_contents($h['log']))->not->toContain('s3cr3t')
        ->and(substr(sprintf('%o', fileperms($dump)), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($h['backup'])), -4))->toBe('0700');
});

test('a failed run does not print the password either', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_FAIL' => 'php artisan migrate --force']);

    expect($result['code'])->not->toBe(0)
        ->and($result['output'])->not->toContain('s3cr3t');
});

test('db settings are read as text and a hostile value is never executed', function () {
    $h = deployHarness([
        'DB_HOST' => 'db;touch HACK_HOST',
        'DB_PASSWORD' => "'\$(touch HACK_PASSWORD)`touch HACK_TICK`'",
        'DB_USERNAME' => 'crm # a comment',
    ]);

    $result = deployRun($h);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($h['log'].'.pgpassword'))->toBe('$(touch HACK_PASSWORD)`touch HACK_TICK`')
        ->and(file_get_contents($h['log']))->toContain('--host=db;touch HACK_HOST --port=5433 --username=crm --dbname=crm_prod')
        ->and(file_exists($h['dir'].'/HACK_HOST'))->toBeFalse()
        ->and(file_exists($h['dir'].'/HACK_PASSWORD'))->toBeFalse()
        ->and(file_exists($h['dir'].'/HACK_TICK'))->toBeFalse();
});

test('missing database settings fall back to the Laravel defaults', function () {
    $h = deployHarness(['DB_HOST' => null, 'DB_PORT' => null, 'DB_DATABASE' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null]);

    $result = deployRun($h);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($h['log']))->toContain('--host=127.0.0.1 --port=5432 --username=root --dbname=laravel')
        ->and(file_get_contents($h['log'].'.pgpassword'))->toBe('');
});

test('a failing step keeps the app down', function (string $step, int $expectedLogLines) {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_FAIL' => $step]);
    $log = deployNormalizedLog(deployLog($h));

    expect($result['code'])->not->toBe(0)
        ->and(end($log))->toStartWith($step)
        ->and($log)->not->toContain('php artisan up')
        ->and($log)->not->toContain('php artisan horizon:terminate')
        ->and($result['output'])->toContain('DEPLOY FAILED at line')
        ->and($result['output'])->toContain('The app is still in maintenance mode and Horizon is paused.')
        ->and($result['output'])->toContain('php artisan horizon:continue && php artisan up')
        ->and(substr_count($result['output'], 'DEPLOY FAILED'))->toBe(1)
        ->and(count($log))->toBe($expectedLogLines);
})->with([
    'wait for jobs' => ['php artisan deploy:wait-for-jobs', 3],
    'git pull' => ['git pull --ff-only', 5],
    'git rev-parse' => ['git rev-parse --short HEAD', 4],
    'composer' => ['composer install', 6],
    'npm build' => ['npm run build', 8],
    'pg_dump' => ['pg_dump', 9],
    'migrate' => ['php artisan migrate --force', 10],
    'optimize:clear' => ['php artisan optimize:clear --except=cache', 11],
]);

test('a failing pg_dump leaves no partial file and keeps the old dumps', function () {
    $h = deployHarness();
    file_put_contents($h['backup'].'/crm-20260101T000000Z-aaaaaaa.dump', 'old');

    $result = deployRun($h, ['STUB_FAIL' => 'pg_dump', 'DEPLOY_BACKUP_KEEP' => '1']);

    expect($result['code'])->not->toBe(0)
        ->and(deployDumps($h))->toBe(['crm-20260101T000000Z-aaaaaaa.dump'])
        ->and($result['output'])->not->toContain('Database backup taken before the migrations');
});

test('a failed migration prints the restore line without the password', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_FAIL' => 'php artisan migrate --force']);
    $dump = $h['backup'].'/'.deployDumps($h)[0];

    expect($result['output'])->toContain('Database backup taken before the migrations: '.$dump)
        ->and($result['output'])->toContain('To restore it: pg_restore --clean --if-exists --no-owner --host=db.internal --port=5433 --username=crm --dbname=crm_prod '.$dump);
});

test('a failing down stops before anything else', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_FAIL' => 'php artisan down']);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Deploy stopped: could not switch maintenance mode on.')
        ->and(deployLog($h))->toHaveCount(1)
        ->and(deployDumps($h))->toBe([]);
});

test('a failing env check keeps the app down and Horizon paused', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_ENV_EXIT' => '1', 'STUB_ENV_OUTPUT' => 'FAIL  something']);
    $log = deployLog($h);

    expect($result['code'])->toBe(1)
        ->and(end($log))->toBe('env-check')
        ->and($log)->not->toContain('php artisan horizon:terminate')
        ->and($log)->not->toContain('php artisan up')
        ->and($result['output'])->toContain('The production .env check failed (see above). The app stays in maintenance mode and Horizon is paused.')
        ->and($result['output'])->not->toContain('Deploy finished.')
        ->and($result['output'])->not->toContain('DEPLOY FAILED');
});

test('env check warnings only still deploy', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_ENV_EXIT' => '0', 'STUB_ENV_OUTPUT' => 'WARN  something']);
    $log = deployLog($h);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('WARN  something')
        ->and(end($log))->toBe('php artisan up');
});

test('retention keeps the newest five dumps', function () {
    $h = deployHarness();
    foreach (range(1, 6) as $day) {
        file_put_contents($h['backup'].'/crm-2026010'.$day.'T000000Z-aaaaaaa.dump', 'old');
    }
    file_put_contents($h['backup'].'/notes.txt', 'keep');
    file_put_contents($h['backup'].'/crm-manual.sql', 'keep');

    $result = deployRun($h);
    $dumps = deployDumps($h);

    expect($result['code'])->toBe(0)
        ->and($dumps)->toHaveCount(5)
        ->and($dumps)->toContain('crm-20260103T000000Z-aaaaaaa.dump', 'crm-20260106T000000Z-aaaaaaa.dump')
        ->and($dumps)->not->toContain('crm-20260101T000000Z-aaaaaaa.dump', 'crm-20260102T000000Z-aaaaaaa.dump')
        ->and(file_exists($h['backup'].'/notes.txt'))->toBeTrue()
        ->and(file_exists($h['backup'].'/crm-manual.sql'))->toBeTrue()
        ->and(file_exists($h['backup'].'/.deploy.lock'))->toBeTrue();
});

test('retention honours DEPLOY_BACKUP_KEEP and never deletes the new dump', function () {
    $h = deployHarness();
    file_put_contents($h['backup'].'/crm-29990101T000000Z-zzzzzzz.dump', 'future');
    file_put_contents($h['backup'].'/crm-20260101T000000Z-aaaaaaa.dump', 'old');
    file_put_contents($h['backup'].'/crm-20260102T000000Z-aaaaaaa.dump', 'old');

    $result = deployRun($h, ['DEPLOY_BACKUP_KEEP' => '2']);
    $dumps = deployDumps($h);

    expect($result['code'])->toBe(0)
        ->and($dumps)->toHaveCount(2)
        ->and($dumps)->toContain('crm-29990101T000000Z-zzzzzzz.dump')
        ->and(implode(' ', $dumps))->toMatch('/crm-\d{8}T\d{6}Z-abc1234\.dump/');
});

test('retention runs only after a successful dump', function () {
    $h = deployHarness();
    foreach (range(1, 5) as $day) {
        file_put_contents($h['backup'].'/crm-2026010'.$day.'T000000Z-aaaaaaa.dump', 'old');
    }

    deployRun($h, ['STUB_FAIL' => 'pg_dump']);

    expect(deployDumps($h))->toHaveCount(5);
});

test('preflight refusals change nothing', function (array $changes, array $env, bool $withEnv, string $message) {
    $h = deployHarness($changes, $withEnv);

    $result = deployRun($h, $env);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain($message)
        ->and(deployLog($h))->toBe([])
        ->and(deployDumps($h))->toBe([]);
})->with([
    'mysql' => [['DB_CONNECTION' => 'mysql'], [], true, 'Deploy stopped: the backup step only supports PostgreSQL (DB_CONNECTION=pgsql).'],
    'no DB_CONNECTION line' => [['DB_CONNECTION' => null], [], true, 'Deploy stopped: the backup step only supports PostgreSQL (DB_CONNECTION=pgsql).'],
    'DB_URL set' => [['DB_URL' => 'postgres://x'], [], true, 'Deploy stopped: DB_URL is set;'],
    'no .env' => [[], [], false, 'Deploy stopped: no .env file in'],
    'keep is zero' => [[], ['DEPLOY_BACKUP_KEEP' => '0'], true, 'Deploy stopped: DEPLOY_BACKUP_KEEP must be a whole number of 1 or more.'],
    'keep is junk' => [[], ['DEPLOY_BACKUP_KEEP' => '5; rm -rf'], true, 'Deploy stopped: DEPLOY_BACKUP_KEEP must be a whole number of 1 or more.'],
    'keep is negative' => [[], ['DEPLOY_BACKUP_KEEP' => '-1'], true, 'Deploy stopped: DEPLOY_BACKUP_KEEP must be a whole number of 1 or more.'],
]);

test('an empty DB_URL line does not block the deploy', function () {
    $h = deployHarness(['DB_URL' => '']);

    expect(deployRun($h)['code'])->toBe(0);
});

test('without pg_dump on the PATH nothing is run', function () {
    $h = deployHarness();
    $tools = deployTempDir('deploy-tools');

    foreach (['bash', 'dirname', 'grep', 'tail', 'mkdir', 'chmod', 'realpath', 'flock', 'rm', 'date', 'cat', 'sed'] as $tool) {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($tool)));
        if ($path !== '') {
            symlink($path, $tools.'/'.$tool);
        }
    }
    foreach (['php', 'git', 'composer', 'npm'] as $name) {
        symlink($h['bin'].'/'.$name, $tools.'/'.$name);
    }

    $result = deployRun($h, [], $tools);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Deploy stopped: pg_dump was not found.')
        ->and(deployLog($h))->toBe([]);
});

test('a backup directory inside the project is refused and not created', function (string $relative) {
    $h = deployHarness();

    $result = deployRun($h, ['DEPLOY_BACKUP_DIR' => $h['dir'].$relative]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Deploy stopped: DEPLOY_BACKUP_DIR must be outside the project directory.')
        ->and(deployLog($h))->toBe([])
        ->and(file_exists($h['dir'].'/storage'))->toBeFalse();
})->with(['', '/storage', '/storage/../storage/backups', '/public']);

test('a backup directory reaching into the project through a symlink or dots is refused', function () {
    $h = deployHarness();
    File::ensureDirectoryExists($h['dir'].'/public');
    symlink($h['dir'].'/public', $h['backup'].'/link');

    $viaLink = deployRun($h, ['DEPLOY_BACKUP_DIR' => $h['backup'].'/link/dumps']);
    $viaDots = deployRun($h, ['DEPLOY_BACKUP_DIR' => $h['backup'].'/../'.basename($h['dir']).'/public']);

    expect($viaLink['code'])->toBe(1)
        ->and($viaLink['output'])->toContain('must be outside the project directory')
        ->and($viaDots['code'])->toBe(1)
        ->and($viaDots['output'])->toContain('must be outside the project directory')
        ->and(deployLog($h))->toBe([])
        ->and(file_exists($h['dir'].'/public/dumps'))->toBeFalse();
});

test('a project directory whose name is a prefix of the backup directory is not mistaken for it', function () {
    $h = deployHarness();
    $sibling = $h['dir'].'-backups';
    File::ensureDirectoryExists($sibling);
    deployCreatedDirs($sibling);

    $result = deployRun($h, ['DEPLOY_BACKUP_DIR' => $sibling]);

    expect($result['code'])->toBe(0);
});

test('a missing backup directory is created with mode 700', function () {
    $h = deployHarness();
    $target = $h['backup'].'/nested/dumps';

    $result = deployRun($h, ['DEPLOY_BACKUP_DIR' => $target]);

    expect($result['code'])->toBe(0)
        ->and(substr(sprintf('%o', fileperms($target)), -4))->toBe('0700')
        ->and(glob($target.'/crm-*.dump'))->toHaveCount(1);
});

test('the default backup directory is crm-backups in HOME', function () {
    $h = deployHarness();
    $home = deployTempDir('deploy-home');

    $result = deployRun($h, ['DEPLOY_BACKUP_DIR' => '', 'HOME' => $home]);

    expect($result['code'])->toBe(0)
        ->and(glob($home.'/crm-backups/crm-*.dump'))->toHaveCount(1);
});

test('a second deploy while one runs is refused', function () {
    $h = deployHarness();
    $lock = fopen($h['backup'].'/.deploy.lock', 'c');
    flock($lock, LOCK_EX);

    $result = deployRun($h);

    flock($lock, LOCK_UN);
    fclose($lock);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Deploy stopped: another deploy is running.')
        ->and(deployLog($h))->toBe([]);
});

test('the lock is released when the deploy ends', function () {
    $h = deployHarness();

    expect(deployRun($h)['code'])->toBe(0)
        ->and(deployRun($h)['code'])->toBe(0);
});

test('a git pull that rewrites deploy.sh does not change what the running script does', function () {
    $h = deployHarness();

    $result = deployRun($h, ['STUB_GIT_APPEND' => 'echo REWRITTEN-SCRIPT-RAN >> "$STUB_LOG"; exit 77']);

    expect($result['code'])->toBe(0)
        ->and(deployNormalizedLog(deployLog($h)))->toBe(deployHappyLog($h))
        ->and(file_get_contents($h['dir'].'/deploy.sh'))->toContain('REWRITTEN-SCRIPT-RAN');
});

test('deploy.sh parses, runs inside main and ends with the main call', function () {
    $script = file_get_contents(base_path('deploy.sh'));

    expect(Process::run(['bash', '-n', base_path('deploy.sh')])->exitCode())->toBe(0)
        ->and($script)->toContain('set -Eeuo pipefail')
        ->and(rtrim($script))->toEndWith("\nmain \"\$@\"");
});

test('the maintenance page is self-contained and escapes the app name', function () {
    (new RegisterErrorViewPaths)();
    config(['app.name' => '<b>Acme</b>']);

    $html = view('errors::503', ['retryAfter' => 60])->render();

    expect($html)->toContain('<title>Back in a minute</title>')
        ->and($html)->toContain('Back in a minute</h1>')
        ->and($html)->toContain('This page reloads by itself.')
        ->and($html)->toContain('&lt;b&gt;Acme&lt;/b&gt;')
        ->and($html)->not->toContain('<b>Acme')
        ->and($html)->not->toContain('build/assets')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<link')
        ->and($html)->toContain('prefers-color-scheme: dark');
});
