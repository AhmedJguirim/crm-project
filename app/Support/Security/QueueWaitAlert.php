<?php

namespace App\Support\Security;

use App\Notifications\QueueWaitAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Events\LongWaitDetected;
use Throwable;

/**
 * Mails the person running the app when Horizon reports that a queue needs longer to clear than its level, at most
 * once per queue an hour (SEC-34). It runs inside the Horizon supervisor loop, so it never throws: every failure is
 * reported and swallowed.
 */
class QueueWaitAlert
{
    public function handle(LongWaitDetected $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function send(LongWaitDetected $event): void
    {
        if (! config('security.queue_wait_alert.enabled')) {
            return;
        }

        $email = config('security.owner_alerts.email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $key = 'queue-wait-alert:'.$event->connection.':'.$event->queue;
        $claim = $this->claim($key);

        if ($claim === 'alreadySent') {
            return;
        }

        try {
            Notification::route('mail', $email)->notify(new QueueWaitAlertNotification($event->connection, $event->queue, (int) $event->seconds));
        } catch (Throwable $exception) {
            if ($claim === 'claimed') {
                $this->giveBack($key);
            }

            report($exception);

            return;
        }

        $this->audit($event);
    }

    /**
     * Takes the queue's hourly slot. A cache that fails never blocks the alert: the mail goes out and the failure is
     * reported.
     *
     * @return 'claimed'|'alreadySent'|'unguarded'
     */
    private function claim(string $key): string
    {
        try {
            return Cache::add($key, true, now()->addHour()) ? 'claimed' : 'alreadySent';
        } catch (Throwable $exception) {
            report($exception);

            return 'unguarded';
        }
    }

    private function giveBack(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function audit(LongWaitDetected $event): void
    {
        try {
            SecurityLog::info('security.queue_wait_alert_sent', context: [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'seconds' => (int) $event->seconds,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
