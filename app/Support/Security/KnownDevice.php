<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A known device is a browser where an account already signed in successfully (SEC-45). It passes the per-account
 * lock of SEC-42 with the right password, so a stranger who locks an address on purpose cannot lock its owner out.
 * The browser is recognised by a signed cookie set at sign-in, never by IP address: an office, a carrier or a shared
 * network would let an attacker on the same IP through, and behind a proxy the IP is only as good as the trusted
 * proxy list. The cookie is bound to the email by a hash and signed with the app key; a stolen cookie gets its own
 * counter of 10 wrong passwords per 15 minutes.
 */
final class KnownDevice
{
    public const string COOKIE = 'known_device';

    public const int LIFETIME_MINUTES = 129600;

    public const int MAX_FAILURES = 10;

    public const int WINDOW_SECONDS = 900;

    /**
     * Marks this browser as known for the account that just signed in. One cookie per browser: the last account
     * that signed in on it is the known one.
     */
    public static function remember(string $email): void
    {
        if (! self::enabled()) {
            return;
        }

        $emailHash = self::emailHash($email);
        $deviceId = Str::random(40);

        Cookie::queue(Cookie::make(self::COOKIE, $emailHash.'|'.$deviceId.'|'.self::signature($emailHash, $deviceId), self::LIFETIME_MINUTES));
    }

    public static function passesLock(string $email): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $deviceId = self::deviceId($email);

        if ($deviceId === null) {
            return false;
        }

        return ! RateLimiter::tooManyAttempts(self::key($deviceId), self::MAX_FAILURES);
    }

    /**
     * Counts a wrong password typed on a known device, so a stolen cookie gets 10 guesses per 15 minutes.
     */
    public static function recordFailure(string $email): void
    {
        if (! self::enabled()) {
            return;
        }

        $deviceId = self::deviceId($email);

        if ($deviceId === null) {
            return;
        }

        RateLimiter::hit(self::key($deviceId), self::WINDOW_SECONDS);
    }

    /**
     * The device id of a valid cookie issued for this email; null for a missing, forged, malformed or foreign cookie.
     */
    private static function deviceId(string $email): ?string
    {
        $value = request()->cookie(self::COOKIE);

        if (! is_string($value)) {
            return null;
        }

        $parts = explode('|', $value);

        if (count($parts) !== 3) {
            return null;
        }

        [$emailHash, $deviceId, $signature] = $parts;

        if (! hash_equals(self::signature($emailHash, $deviceId), $signature)) {
            return null;
        }

        if (! hash_equals(self::emailHash($email), $emailHash)) {
            return null;
        }

        return $deviceId;
    }

    private static function enabled(): bool
    {
        return (bool) config('security.known_device_login.enabled');
    }

    private static function emailHash(string $email): string
    {
        return sha1(Str::lower(trim($email)));
    }

    private static function signature(string $emailHash, string $deviceId): string
    {
        return hash_hmac('sha256', 'known-device|'.$emailHash.'|'.$deviceId, (string) config('app.key'));
    }

    private static function key(string $deviceId): string
    {
        return 'login-device:'.sha1($deviceId);
    }
}
