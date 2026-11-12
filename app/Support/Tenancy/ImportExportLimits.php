<?php

namespace App\Support\Tenancy;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Caps how many imports and how many exports one user (across all their organizations) and one organization can have
 * waiting or running at once (SEC-32), so one account cannot keep every `imports` worker busy. Imports and exports are
 * two separate pools.
 *
 * A slot is one queued-or-running job: claimed by the action right before it dispatches the job, released by the job
 * when it ends. Slots are named entries (a UUID with its own expiry) and not counters, because a job can release twice
 * (the run ends with an exception, then `failed()` runs on a fresh copy of the job) and a counter would under-count
 * after the second release; releasing a named entry is idempotent. An entry expires by itself after
 * {@see self::SLOT_LIFETIME_SECONDS}, so a lost job (a killed worker, a flushed cache) frees its slot. Every claim and
 * release runs inside one global cache lock, so two tabs cannot pass the same count.
 */
final class ImportExportLimits
{
    public const string IMPORTS = 'imports';

    public const string EXPORTS = 'exports';

    public const int IMPORTS_PER_USER = 2;

    public const int IMPORTS_PER_ORGANIZATION = 3;

    public const int EXPORTS_PER_USER = 2;

    public const int EXPORTS_PER_ORGANIZATION = 3;

    public const int SLOT_LIFETIME_SECONDS = 14400;

    public const int LOCK_WAIT_SECONDS = 3;

    private const string LOCK_KEY = 'import-export-slots:lock';

    /**
     * Takes one slot for the organization and the user, unless a cap is reached or the lock stays busy. Takes nothing
     * when it refuses. The organization pool is checked first, then the user pool.
     *
     * @param  self::IMPORTS|self::EXPORTS  $kind
     * @return string|null the text to show when the job must not be queued, null when the slot was taken
     */
    public static function claim(string $kind, int $organizationId, int $userId, string $slot): ?string
    {
        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(
                self::LOCK_WAIT_SECONDS,
                fn (): ?string => self::claimLocked($kind, $organizationId, $userId, $slot),
            );
        } catch (LockTimeoutException) {
            return 'Too many files are being queued right now. Try again in a moment.';
        }
    }

    /**
     * Gives one slot back to both pools. A null or unknown slot does nothing, so releasing twice is harmless. When the
     * lock stays busy nothing is done and the slot expires by itself.
     *
     * @param  self::IMPORTS|self::EXPORTS  $kind
     */
    public static function release(string $kind, int $organizationId, int $userId, ?string $slot): void
    {
        if ($slot === null) {
            return;
        }

        try {
            Cache::lock(self::LOCK_KEY, 10)->block(self::LOCK_WAIT_SECONDS, function () use ($kind, $organizationId, $userId, $slot): void {
                foreach ([self::organizationKey($kind, $organizationId), self::userKey($kind, $userId)] as $key) {
                    $slots = self::liveSlots($key);

                    if (! array_key_exists($slot, $slots)) {
                        continue;
                    }

                    unset($slots[$slot]);

                    self::store($key, $slots);
                }
            });
        } catch (LockTimeoutException) {
            return;
        }
    }

    private static function claimLocked(string $kind, int $organizationId, int $userId, string $slot): ?string
    {
        $organizationKey = self::organizationKey($kind, $organizationId);
        $userKey = self::userKey($kind, $userId);

        $organizationSlots = self::liveSlots($organizationKey);
        $userSlots = self::liveSlots($userKey);

        $imports = $kind === self::IMPORTS;
        $organizationCap = $imports ? self::IMPORTS_PER_ORGANIZATION : self::EXPORTS_PER_ORGANIZATION;
        $userCap = $imports ? self::IMPORTS_PER_USER : self::EXPORTS_PER_USER;

        if (count($organizationSlots) >= $organizationCap) {
            return $imports
                ? 'This organization already has '.$organizationCap.' imports waiting or running. Try again when one of them has finished.'
                : 'This organization already has '.$organizationCap.' exports waiting or being built. Try again when one of them is ready.';
        }

        if (count($userSlots) >= $userCap) {
            return $imports
                ? 'You already have '.$userCap.' imports waiting or running. Try again when one of them has finished.'
                : 'You already have '.$userCap.' exports waiting or being built. Try again when one of them is ready.';
        }

        $expiresAt = now()->getTimestamp() + self::SLOT_LIFETIME_SECONDS;

        self::store($organizationKey, $organizationSlots + [$slot => $expiresAt]);
        self::store($userKey, $userSlots + [$slot => $expiresAt]);

        return null;
    }

    /**
     * @return array<string, int> slot ⇒ expiry timestamp, without the expired entries
     */
    private static function liveSlots(string $key): array
    {
        $stored = Cache::get($key);

        if (! is_array($stored)) {
            return [];
        }

        $now = now()->getTimestamp();

        return array_filter($stored, fn (mixed $expiresAt): bool => is_int($expiresAt) && $expiresAt > $now);
    }

    /**
     * @param  array<string, int>  $slots
     */
    private static function store(string $key, array $slots): void
    {
        if ($slots === []) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $slots, self::SLOT_LIFETIME_SECONDS);
    }

    private static function organizationKey(string $kind, int $organizationId): string
    {
        return "import-export-slots:{$kind}:organization:{$organizationId}";
    }

    private static function userKey(string $kind, int $userId): string
    {
        return "import-export-slots:{$kind}:user:{$userId}";
    }
}
