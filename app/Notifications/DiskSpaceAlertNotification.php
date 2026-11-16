<?php

namespace App\Notifications;

use App\Enums\DiskAlertLevel;
use App\Support\Security\DiskSpace;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person running the app that the disk holding storage/ is running low on free space (SEC-47). It is sent to
 * an address, not to a user, and is deliberately not queued: a full disk must not stop the alert about the full disk.
 * It carries only config values and numbers.
 */
class DiskSpaceAlertNotification extends Notification
{
    public function __construct(
        public DiskAlertLevel $level,
        public float $freeBytes,
        public float $totalBytes,
        public float $thresholdGb,
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
        $free = number_format($this->freeBytes / DiskSpace::BYTES_PER_GB, 1);
        $total = number_format($this->totalBytes / DiskSpace::BYTES_PER_GB, 1);
        $used = (int) round(($this->totalBytes - $this->freeBytes) / $this->totalBytes * 100);
        $threshold = number_format($this->thresholdGb, 1);

        $consequence = match ($this->level) {
            DiskAlertLevel::Warning => "This is below the warning level of {$threshold} GB free.",
            DiskAlertLevel::Critical => "This is below the critical level of {$threshold} GB free. When the disk is full, sign-ins, uploads, logs and queued jobs fail for every user.",
        };

        return (new MailMessage)
            ->subject("Disk space {$this->level->value}: {$free} GB free on {$name}")
            ->line("The disk that holds the storage folder of {$name} has {$free} GB free of {$total} GB ({$used}% used).")
            ->line($consequence)
            ->line('To free space: run php artisan uploads:prune and php artisan exports:prune, remove old files in storage/logs, and check the size of the database.')
            ->line('You get at most one alert of each level a day.');
    }
}
