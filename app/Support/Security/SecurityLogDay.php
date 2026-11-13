<?php

namespace App\Support\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * What one day of the daily `security` log file says about sign-in abuse (SEC-44). Only lines written by this
 * environment count, and only values that pass an allow-list (a valid IP address, a positive integer id, a known
 * lockout source) are kept, so nothing free-form from the log can reach the owner's mail.
 */
final readonly class SecurityLogDay
{
    /** Distinct IP addresses, and distinct locked account ids, tracked per day; the totals stay exact beyond it. */
    public const int MAX_TRACKED = 10000;

    public const string UNKNOWN_IP = 'unknown';

    public const string OTHER_SOURCE = 'other';

    /**
     * Lockout sources written by `SecurityLog`, in the order the mail lists them.
     *
     * @var list<string>
     */
    public const array LOCKOUT_SOURCES = ['panel_account', 'panel', 'password.email', 'password.update'];

    private const string LINE_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] (?<env>[A-Za-z0-9_-]+)\.(?<level>[A-Z]+): (?<event>[a-z_.]+) (?<context>\{.*\})/';

    /**
     * @param  array<string, int>  $lockoutsBySource
     * @param  list<int>  $lockedUserIds
     * @param  array<string, int>  $failedLoginsByIp
     */
    public function __construct(
        public CarbonImmutable $day,
        public int $failedLogins,
        public int $failedTwoFactor,
        public array $lockoutsBySource,
        public array $lockedUserIds,
        public array $failedLoginsByIp,
        public bool $trackingCapped,
    ) {}

    public static function read(CarbonImmutable $day): self
    {
        $channel = (string) (config('logging.channels.security.name') ?? app()->environment());
        $path = self::pathFor($day);

        $failedLogins = 0;
        $failedTwoFactor = 0;
        $lockoutsBySource = [];
        $lockedUserIds = [];
        $failedLoginsByIp = [];
        $capped = false;

        if (is_file($path)) {
            foreach (File::lines($path) as $line) {
                if (preg_match(self::LINE_PATTERN, $line, $match) !== 1 || $match['env'] !== $channel) {
                    continue;
                }

                $context = json_decode($match['context'], true);
                $context = is_array($context) ? $context : [];

                if ($match['event'] === 'auth.login_failed') {
                    $failedLogins++;
                    $ip = $context['ip'] ?? null;
                    $ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : self::UNKNOWN_IP;

                    if (isset($failedLoginsByIp[$ip])) {
                        $failedLoginsByIp[$ip]++;
                    } elseif (count($failedLoginsByIp) < self::MAX_TRACKED) {
                        $failedLoginsByIp[$ip] = 1;
                    } else {
                        $capped = true;
                    }

                    continue;
                }

                if ($match['event'] === 'auth.two_factor_failed') {
                    $failedTwoFactor++;

                    continue;
                }

                if ($match['event'] !== 'auth.lockout') {
                    continue;
                }

                $source = $context['source'] ?? null;
                $source = is_string($source) && in_array($source, self::LOCKOUT_SOURCES, true) ? $source : self::OTHER_SOURCE;
                $lockoutsBySource[$source] = ($lockoutsBySource[$source] ?? 0) + 1;

                $userId = $context['user_id'] ?? null;

                if (! is_int($userId) || $userId <= 0 || isset($lockedUserIds[$userId])) {
                    continue;
                }

                if (count($lockedUserIds) < self::MAX_TRACKED) {
                    $lockedUserIds[$userId] = true;
                } else {
                    $capped = true;
                }
            }
        }

        $lockedUserIds = array_keys($lockedUserIds);
        sort($lockedUserIds);

        return new self($day, $failedLogins, $failedTwoFactor, $lockoutsBySource, $lockedUserIds, $failedLoginsByIp, $capped);
    }

    public function lockouts(): int
    {
        return array_sum($this->lockoutsBySource);
    }

    public function crosses(int $threshold): bool
    {
        return $this->failedLogins > $threshold || $this->lockouts() >= 1;
    }

    public function fileName(): string
    {
        return basename(self::pathFor($this->day));
    }

    private static function pathFor(CarbonImmutable $day): string
    {
        $path = (string) config('logging.channels.security.path');

        return dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-'.$day->format('Y-m-d').'.'.pathinfo($path, PATHINFO_EXTENSION);
    }
}
