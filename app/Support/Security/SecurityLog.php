<?php

namespace App\Support\Security;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes one line per security event to the daily `security` log channel (SEC-08). The line always carries the user id,
 * the organization id and the IP, and never a password, email address, token or recovery code.
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
