<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Locks an email address after too many failed panel sign-ins, from any IP (SEC-42). It stacks with Filament's
 * per-IP limit. The counter lives in the cache through the RateLimiter, in one fixed window that starts at the first
 * failure: 10 failures within 15 minutes lock the email until those 15 minutes are over. The key is the hash of the
 * trimmed, lower-cased typed email, so no address sits in a cache key and case or spaces never make a second counter;
 * it is kept whether or not a user exists, so the lock does not reveal which addresses have an account.
 */
final class LoginLimits
{
    public const int MAX_FAILURES = 10;

    public const int WINDOW_SECONDS = 900;

    public static function isLocked(string $email): bool
    {
        return RateLimiter::tooManyAttempts(self::key($email), self::MAX_FAILURES);
    }

    public static function secondsUntilUnlocked(string $email): int
    {
        return RateLimiter::availableIn(self::key($email));
    }

    /**
     * @return int the number of failures counted in the current window, this one included
     */
    public static function recordFailure(string $email): int
    {
        return RateLimiter::hit(self::key($email), self::WINDOW_SECONDS);
    }

    public static function clear(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }

    private static function key(string $email): string
    {
        return 'login-account:'.sha1(Str::lower(trim($email)));
    }
}
