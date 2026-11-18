<?php

namespace App\Support\Security;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Writes one line per security event to the daily `security` log channel (SEC-08). The line always carries the user id,
 * the organization id and the IP, and never a password, email address, token or recovery code. Refusal lines
 * (`refusal()`) are capped per actor so a loop of forged requests cannot fill the disk (SEC-21).
 */
final class SecurityLog
{
    public const string CHANNEL = 'security';

    /**
     * Named routes whose 429 answer is a sign-in or password-reset lockout.
     *
     * @var list<string>
     */
    public const array THROTTLED_AUTH_ROUTES = ['password.email', 'password.update'];

    public const int REFUSALS_PER_MINUTE = 10;

    public const int REFUSAL_WINDOW_SECONDS = 60;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function info(string $event, ?Authenticatable $user = null, array $context = []): void
    {
        self::write('info', $event, $user, $context);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function warning(string $event, ?Authenticatable $user = null, array $context = []): void
    {
        self::write('warning', $event, $user, $context);
    }

    /**
     * Writes one refused request as a warning, at most REFUSALS_PER_MINUTE lines a minute per signed-in user (per IP for a
     * guest) across every refusal kind. The next call writes one `request.refusals_capped` line and the rest write
     * nothing. A cache failure never hides the line: evidence beats the cap when the cache is down.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function refusal(string $event, array $context = []): void
    {
        $user = request()->user();
        $identifier = $user?->getAuthIdentifier();
        $actor = $identifier !== null ? 'user:'.$identifier : 'ip:'.request()->ip();

        $hits = rescue(fn (): int => RateLimiter::hit('security-log-refusals:'.$actor, self::REFUSAL_WINDOW_SECONDS), 1, report: false);

        if ($hits <= self::REFUSALS_PER_MINUTE) {
            self::warning($event, $user, $context);

            return;
        }

        if ($hits === self::REFUSALS_PER_MINUTE + 1) {
            self::warning('request.refusals_capped', $user, ['cap' => self::REFUSALS_PER_MINUTE, 'window_seconds' => self::REFUSAL_WINDOW_SECONDS]);
        }
    }

    /**
     * Logs a lockout when a throttled request was a sign-in or password-reset route; any other 429 logs nothing.
     */
    public static function throttledAuthRequest(Request $request): void
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || ! in_array($routeName, self::THROTTLED_AUTH_ROUTES, true)) {
            return;
        }

        self::warning('auth.lockout', $request->user(), ['source' => $routeName]);
    }

    /**
     * A broken log file must never block a sign-in or a password change, so a failure is reported and swallowed.
     *
     * @param  array<string, scalar|null>  $context
     */
    private static function write(string $level, string $event, ?Authenticatable $user, array $context): void
    {
        $context = array_merge($context, [
            'user_id' => $user?->getAuthIdentifier(),
            'organization_id' => CurrentOrganization::find()?->getKey(),
            'ip' => request()->ip(),
        ]);

        rescue(fn () => Log::channel(self::CHANNEL)->log($level, $event, $context));
    }
}
