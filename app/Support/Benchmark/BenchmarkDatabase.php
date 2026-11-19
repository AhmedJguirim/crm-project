<?php

namespace App\Support\Benchmark;

/**
 * The one guard shared by every volume benchmark tool (the seeder and the benchmark commands): they run only with
 * `--env=benchmark` on the `srw-crm-bench` database (or the testing environment on a PHPUnit database), on
 * PostgreSQL, with an uncached config and synchronous queues. The database name is checked and not only the
 * environment because a missing `.env.benchmark` makes `--env=benchmark` fall back to `.env`, i.e. the development
 * database with Redis queues; a Redis queue would hand the segment jobs to the development workers.
 */
final class BenchmarkDatabase
{
    public const string NAME = 'srw-crm-bench';

    public const string OWNER_EMAIL = 'bench-owner@bench.invalid';

    /**
     * Whether the app currently points at a database the benchmark may write to. Reads the config on every call.
     */
    public static function isSafeTarget(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $environment = app()->environment();

        $targetAllowed = ($environment === 'benchmark' && $database === self::NAME)
            || ($environment === 'testing' && preg_match('/^srw-crm-test(_test_\d+)?\z/', $database) === 1);

        return ! app()->configurationIsCached()
            && $connection === 'pgsql'
            && $targetAllowed
            && config('queue.default') === 'sync'
            && config('queue.long_running_connection') === 'sync';
    }

    /**
     * The environment, connection, database and queues as found, for a refusal message.
     */
    public static function describeTarget(): string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $queue = config('queue.default').'/'.config('queue.long_running_connection');

        return app()->environment().", {$connection}/{$database}, queue {$queue}";
    }
}
