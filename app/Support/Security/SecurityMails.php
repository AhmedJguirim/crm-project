<?php

namespace App\Support\Security;

use App\Enums\AccountSecurityEvent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Mails the owner of an account about a security change on it (SEC-43), behind `config('security.user_mails.enabled')`
 * and a cap of 10 mails per user and hour. A failure here is reported and never undoes or blocks the change itself.
 */
final class SecurityMails
{
    public const int HOURLY_CAP = 10;

    /**
     * Sends to the user's own address, or to `$toAddress` when the user's address already changed (the previous one).
     */
    public static function send(User $user, AccountSecurityEvent $event, ?string $toAddress = null): void
    {
        if (! config('security.user_mails.enabled')) {
            return;
        }

        try {
            if (RateLimiter::hit('security-mail:'.$user->getKey(), 3600) > self::HOURLY_CAP) {
                SecurityLog::warning('account.security_mail_capped', $user, ['event' => $event->value]);

                return;
            }

            $notification = new AccountSecurityNotification($event, CarbonImmutable::now('UTC'), request()?->ip());

            if ($toAddress === null) {
                $user->notify($notification);

                return;
            }

            Notification::route('mail', $toAddress)->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
