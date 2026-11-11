<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseRealMailOrQueue();
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
}
