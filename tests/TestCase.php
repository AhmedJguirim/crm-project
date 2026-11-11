<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public const string TESTING_DATABASE = 'srw-crm-test';

    /**
     * Creates the application and runs the safety guards right away, before the parallel callbacks and the
     * traits (`RefreshDatabase` would run `migrate:fresh` on whatever database is configured).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->refuseRealMailOrQueue();
        $this->refuseNonTestingDatabase();
    }

    /**
     * Stops the test run when it could reach a real mailer or a real queue (a cached config, an exported
     * MAIL_MAILER / QUEUE_CONNECTION, or a Resend key that leaked in from the `.env` file).
     *
     * @throws RuntimeException
     */
    private function refuseRealMailOrQueue(): void
    {
        $unsafe = $this->app->configurationIsCached()
            || config('mail.default') !== 'array'
            || filled(config('services.resend.key'))
            || filled(config('resend.api_key'))
            || config('queue.default') !== 'sync'
            || config('queue.long_running_connection') !== 'sync';

        if ($unsafe) {
            throw new RuntimeException('Refusing to run the tests: they could reach a real mailer or queue. Run php artisan config:clear and check phpunit.xml.');
        }
    }

    /**
     * Stops the test run unless the default connection is pgsql on the testing database (or a parallel worker
     * copy of it), so `migrate:fresh` can never reach the dev database.
     *
     * @throws RuntimeException
     */
    private function refuseNonTestingDatabase(): void
    {
        $database = (string) config('database.connections.pgsql.database');

        $unsafe = config('database.default') !== 'pgsql'
            || preg_match('/^'.preg_quote(self::TESTING_DATABASE, '/').'(_test_\d+)?\z/', $database) !== 1;

        if ($unsafe) {
            throw new RuntimeException(sprintf(
                "Refusing to run the tests: the database '%s' is not the testing database '%s'. Run php artisan config:clear and check .env.testing and phpunit.xml.",
                config('database.default').'/'.$database,
                self::TESTING_DATABASE,
            ));
        }
    }
}
