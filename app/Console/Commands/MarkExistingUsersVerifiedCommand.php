<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

class MarkExistingUsersVerifiedCommand extends Command
{
    protected $signature = 'users:mark-existing-verified {--created-before= : UTC date-time (Y-m-d H:i:s); users created before it are marked verified} {--dry-run : Only count the users}';

    protected $description = 'Temporary (SEC-24): mark the users created before the email verification deploy as verified';

    /**
     * Marks the unverified users created before the given UTC time as verified, or only counts them with `--dry-run`.
     * Nothing is written when the time is missing or is not an exact `Y-m-d H:i:s`.
     */
    public function handle(): int
    {
        $value = $this->option('created-before');
        $cutoff = is_string($value) ? $this->parseCutoff($value) : null;

        if ($cutoff === null) {
            $this->error('Give --created-before as a UTC date-time, for example --created-before="2026-11-20 00:00:00".');

            return self::FAILURE;
        }

        $users = User::query()->whereNull('email_verified_at')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = $users->count();
            $this->info("{$count} users created before {$value} UTC would be marked verified.");

            return self::SUCCESS;
        }

        $count = $users->update(['email_verified_at' => now()]);
        $this->info("Marked {$count} users created before {$value} UTC as verified.");

        return self::SUCCESS;
    }

    private function parseCutoff(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }
}
