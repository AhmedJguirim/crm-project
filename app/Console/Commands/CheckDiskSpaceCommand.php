<?php

namespace App\Console\Commands;

use App\Enums\DiskAlertLevel;
use App\Notifications\DiskSpaceAlertNotification;
use App\Support\Security\DiskSpace;
use App\Support\Security\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Mails the person running the app when the disk that holds storage/ runs low on free space, at most once per level per
 * day (SEC-47).
 */
class CheckDiskSpaceCommand extends Command
{
    protected $signature = 'security:check-disk';

    protected $description = 'Mail the app owner when the disk that holds storage/ has less free space than the warning or critical level';

    public function handle(DiskSpace $diskSpace): int
    {
        if (! config('security.disk_alert.enabled')) {
            $this->info('Disk space alerts are turned off (SECURITY_DISK_ALERT_ENABLED=false).');

            return self::SUCCESS;
        }

        $warnGb = max(0.0, (float) config('security.disk_alert.warn_free_gb'));
        $criticalGb = max(0.0, (float) config('security.disk_alert.critical_free_gb'));

        if ($criticalGb > $warnGb) {
            $this->error('SECURITY_DISK_CRITICAL_FREE_GB must not be higher than SECURITY_DISK_WARN_FREE_GB; no disk check done.');

            return self::FAILURE;
        }

        $reading = $diskSpace->read(storage_path());

        if ($reading === null) {
            $this->error('Could not read the free space of the storage disk; no disk check done.');

            return self::FAILURE;
        }

        $free = $reading['free'];
        $total = $reading['total'];
        $freeGb = number_format($free / DiskSpace::BYTES_PER_GB, 1);
        $level = $this->level($free, $warnGb, $criticalGb);

        if ($level === null) {
            $totalGb = number_format($total / DiskSpace::BYTES_PER_GB, 1);
            $this->info("Disk space OK: {$freeGb} GB free of {$totalGb} GB.");

            return self::SUCCESS;
        }

        $email = config('security.owner_alerts.email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('SECURITY_OWNER_ALERTS_EMAIL is not a valid email address; no disk alert sent.');

            return self::FAILURE;
        }

        $date = now()->format('Y-m-d');
        $key = self::cacheKey($level, $date);
        $claim = $this->claim($level, $date, $key);

        if ($claim === 'criticalSent') {
            $this->info("The critical disk alert for {$date} was already sent; no warning sent.");

            return self::SUCCESS;
        }

        if ($claim === 'alreadySent') {
            $this->info("The {$level->value} disk alert for {$date} was already sent.");

            return self::SUCCESS;
        }

        $thresholdGb = $level === DiskAlertLevel::Critical ? $criticalGb : $warnGb;

        try {
            Notification::route('mail', $email)->notify(new DiskSpaceAlertNotification($level, $free, $total, $thresholdGb));
        } catch (Throwable $exception) {
            if ($claim === 'claimed') {
                $this->giveBack($key);
            }

            report($exception);
            $this->error("The {$level->value} disk alert could not be sent.");

            return self::FAILURE;
        }

        $this->audit($level, $free, $total);
        $this->info("{$level->label()} disk alert sent to the owner address: {$freeGb} GB free.");

        return self::SUCCESS;
    }

    private static function cacheKey(DiskAlertLevel $level, string $date): string
    {
        return 'security-disk-alert:'.$level->value.':'.$date;
    }

    private function level(float $free, float $warnGb, float $criticalGb): ?DiskAlertLevel
    {
        if ($free < $criticalGb * DiskSpace::BYTES_PER_GB) {
            return DiskAlertLevel::Critical;
        }

        if ($free < $warnGb * DiskSpace::BYTES_PER_GB) {
            return DiskAlertLevel::Warning;
        }

        return null;
    }

    /**
     * Takes the day's slot for the level. A cache that fails (it may sit on the full disk) never blocks the alert: the
     * mail goes out and the failure is reported.
     *
     * @return 'claimed'|'alreadySent'|'criticalSent'|'unguarded'
     */
    private function claim(DiskAlertLevel $level, string $date, string $key): string
    {
        try {
            if ($level === DiskAlertLevel::Warning && Cache::has(self::cacheKey(DiskAlertLevel::Critical, $date))) {
                return 'criticalSent';
            }

            return Cache::add($key, true, now()->addDays(2)) ? 'claimed' : 'alreadySent';
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

    private function audit(DiskAlertLevel $level, float $free, float $total): void
    {
        try {
            SecurityLog::info('security.disk_alert_sent', context: [
                'level' => $level->value,
                'free_gb' => round($free / DiskSpace::BYTES_PER_GB, 1),
                'total_gb' => round($total / DiskSpace::BYTES_PER_GB, 1),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
