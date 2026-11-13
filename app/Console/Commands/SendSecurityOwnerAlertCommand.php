<?php

namespace App\Console\Commands;

use App\Notifications\SecurityOwnerAlertNotification;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityLogDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Mails the person running the app once a day when the security log of the day before shows too many failed sign-ins
 * or any lockout (SEC-44).
 */
class SendSecurityOwnerAlertCommand extends Command
{
    protected $signature = 'security:alert-owner {--date= : A past day to report on, YYYY-MM-DD (default: yesterday)}';

    protected $description = "Mail the app owner when yesterday's security log shows too many failed sign-ins or any lockout";

    public function handle(): int
    {
        if (! config('security.owner_alerts.enabled')) {
            $this->info('Owner security alerts are turned off (SECURITY_OWNER_ALERTS_ENABLED=false).');

            return self::SUCCESS;
        }

        $email = config('security.owner_alerts.email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('SECURITY_OWNER_ALERTS_EMAIL is not a valid email address; no alert sent.');

            return self::FAILURE;
        }

        $day = $this->day();

        if ($day === null) {
            $this->error('The --date option must be a past day written YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (config('logging.channels.security.driver') !== 'daily') {
            $this->error('The security log channel is not a daily file, so there is nothing to read.');

            return self::FAILURE;
        }

        $date = $day->format('Y-m-d');
        $threshold = max(0, (int) config('security.owner_alerts.failed_logins_threshold'));
        $log = SecurityLogDay::read($day);

        if (! $log->crosses($threshold)) {
            $this->info("No security alert for {$date}: {$log->failedLogins} failed sign-ins, {$log->lockouts()} lockouts.");

            return self::SUCCESS;
        }

        $key = 'security-owner-alert:'.$date;

        if (! Cache::add($key, true, now()->addDays(3))) {
            $this->info("The security alert for {$date} was already sent.");

            return self::SUCCESS;
        }

        try {
            Notification::route('mail', $email)->notify(new SecurityOwnerAlertNotification($log, $threshold));
        } catch (Throwable $exception) {
            Cache::forget($key);
            report($exception);
            $this->error("The security alert for {$date} could not be queued.");

            return self::FAILURE;
        }

        SecurityLog::info('security.owner_alert_sent', context: [
            'date' => $date,
            'failed_logins' => $log->failedLogins,
            'lockouts' => $log->lockouts(),
        ]);

        $this->info("Security alert for {$date} queued to the owner address.");

        return self::SUCCESS;
    }

    private function day(): ?CarbonImmutable
    {
        $value = $this->option('date');
        $today = CarbonImmutable::now()->startOfDay();

        if ($value === null) {
            return $today->subDay();
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $value || ! $day->lessThan($today)) {
            return null;
        }

        return $day;
    }
}
