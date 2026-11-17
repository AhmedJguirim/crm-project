<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends the verification mail of the panel, at most 5 per user per 24 hours (SEC-24). The counter lives in the cache
 * through the RateLimiter and is incremented first, then compared, so parallel requests cannot pass the same count.
 */
final class VerificationMails
{
    public const int PER_DAY = 5;

    public const int WINDOW_SECONDS = 86400;

    /**
     * Queues the verification mail to the panel link unless the user is verified or the cap is reached.
     *
     * @return string|null the text to show when the mail is refused, null when it was sent or nothing had to be sent
     */
    public static function send(User $user): ?string
    {
        if ($user->hasVerifiedEmail()) {
            return null;
        }

        $key = "verification-mails:user:{$user->id}";

        if (RateLimiter::increment($key, self::WINDOW_SECONDS) > self::PER_DAY) {
            RateLimiter::decrement($key, self::WINDOW_SECONDS);

            return 'You have asked for '.self::PER_DAY.' verification emails in the last 24 hours. Try again later.';
        }

        $notification = new QueuedVerifyEmail;
        $notification->url = Filament::getPanel('admin')->getVerifyEmailUrl($user);

        $user->notify($notification);

        return null;
    }
}
