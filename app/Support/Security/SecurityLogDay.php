<?php

namespace App\Support\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * What one day of the daily `security` log file says about sign-in abuse (SEC-44) and about exports (SEC-60). Only lines
 * written by this environment count, and only values that pass an allow-list (a valid IP address, a positive integer
 * id, a known lockout source, a non-negative integer row count) are kept, so nothing free-form from the log can reach
 * the owner's mail.
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
     * @param  array<int, array{exports: int, rows: int, downloads: int}>  $exportsByUser
     */
    public function __construct(
        public CarbonImmutable $day,
        public int $failedLogins,
        public int $failedTwoFactor,
        public array $lockoutsBySource,
        public array $lockedUserIds,
        public array $failedLoginsByIp,
        public bool $trackingCapped,
        public int $exportsQueued = 0,
        public int $exportedRows = 0,
        public int $exportDownloads = 0,
        public array $exportsByUser = [],
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
        $exportsQueued = 0;
        $exportedRows = 0;
        $exportDownloads = 0;
        $exportsByUser = [];

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

                if ($match['event'] === 'data.export_queued') {
                    $exportsQueued++;
                    $rows = $context['rows'] ?? null;
                    $rows = is_int($rows) && $rows >= 0 ? $rows : 0;
                    $exportedRows = self::addRows($exportedRows, $rows);

                    $userId = self::accountId($context);

                    if ($userId !== null && self::trackExportAccount($exportsByUser, $userId)) {
                        $exportsByUser[$userId]['exports']++;
                        $exportsByUser[$userId]['rows'] = self::addRows($exportsByUser[$userId]['rows'], $rows);
                    } elseif ($userId !== null) {
                        $capped = true;
                    }

                    continue;
                }

                if ($match['event'] === 'data.export_downloaded') {
                    $exportDownloads++;

                    $userId = self::accountId($context);

                    if ($userId !== null && self::trackExportAccount($exportsByUser, $userId)) {
                        $exportsByUser[$userId]['downloads']++;
                    } elseif ($userId !== null) {
                        $capped = true;
                    }

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

        return new self(
            $day,
            $failedLogins,
            $failedTwoFactor,
            $lockoutsBySource,
            $lockedUserIds,
            $failedLoginsByIp,
            $capped,
            $exportsQueued,
            $exportedRows,
            $exportDownloads,
            $exportsByUser,
        );
    }

    /**
     * Adds two row counts and stops at PHP_INT_MAX instead of overflowing to a float, so a forged line can never
     * make the day unreadable.
     */
    private static function addRows(int $sum, int $rows): int
    {
        return $rows > PHP_INT_MAX - $sum ? PHP_INT_MAX : $sum + $rows;
    }

    /**
     * @param  array<mixed>  $context
     */
    private static function accountId(array $context): ?int
    {
        $userId = $context['user_id'] ?? null;

        return is_int($userId) && $userId > 0 ? $userId : null;
    }

    /**
     * Makes room for the account in the map when it is already there or the map is not full yet.
     *
     * @param  array<int, array{exports: int, rows: int, downloads: int}>  $exportsByUser
     */
    private static function trackExportAccount(array &$exportsByUser, int $userId): bool
    {
        if (isset($exportsByUser[$userId])) {
            return true;
        }

        if (count($exportsByUser) >= self::MAX_TRACKED) {
            return false;
        }

        $exportsByUser[$userId] = ['exports' => 0, 'rows' => 0, 'downloads' => 0];

        return true;
    }

    public function lockouts(): int
    {
        return array_sum($this->lockoutsBySource);
    }

    public function crosses(int $threshold): bool
    {
        return $this->failedLogins > $threshold || $this->lockouts() >= 1;
    }

    /**
     * Ids of the accounts that, in the day, queued more exports, exported more rows or downloaded more export files
     * than the limits, ascending.
     *
     * @return list<int>
     */
    public function exportAccountsAbove(ExportAlertLimits $limits): array
    {
        $ids = [];

        foreach ($this->exportsByUser as $userId => $counts) {
            if ($counts['exports'] > $limits->exports || $counts['rows'] > $limits->rows || $counts['downloads'] > $limits->downloads) {
                $ids[] = $userId;
            }
        }

        sort($ids);

        return $ids;
    }

    public function crossesExportLimits(ExportAlertLimits $limits): bool
    {
        return $this->exportAccountsAbove($limits) !== [];
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
