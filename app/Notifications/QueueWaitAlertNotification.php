<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the person running the app that a queue needs longer to clear than its alert level (SEC-34). It is sent to an
 * address, not to a user, and is deliberately not queued: an alert about a stalled queue must not wait on that queue.
 * It carries only config values, the queue name and numbers.
 */
class QueueWaitAlertNotification extends Notification
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $seconds,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = config('app.name');
        $waits = config('horizon.waits');
        $level = is_array($waits) ? (int) ($waits["{$this->connection}:{$this->queue}"] ?? 60) : 60;
        $minutes = max(1, (int) ceil($this->seconds / 60));
        $levelMinutes = max(1, (int) ceil($level / 60));
        $minutesText = $minutes.' '.Str::plural('minute', $minutes);
        $levelText = $levelMinutes.' '.Str::plural('minute', $levelMinutes);

        $consequence = match ($this->queue) {
            'imports' => 'Imports that users queued are waiting to start.',
            'exports' => 'Exports that users queued are waiting to be prepared.',
            'segments' => 'Segment member lists stay out of date until the queue clears.',
            'default' => 'Mails, notifications and segment re-checks are delayed.',
            default => 'Jobs on this queue are delayed.',
        };

        $url = url((string) config('horizon.path'));

        return (new MailMessage)
            ->subject("Queue delay: \"{$this->queue}\" on {$name}")
            ->line("The \"{$this->queue}\" queue of {$name} needs about {$minutesText} to work through the jobs waiting on it, more than the alert level of {$levelText}.")
            ->line($consequence)
            ->line("Open Horizon at {$url} to see which jobs are waiting and whether the workers are running.")
            ->line('You get at most one alert per queue an hour.');
    }
}
