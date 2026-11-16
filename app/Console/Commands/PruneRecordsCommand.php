<?php

namespace App\Console\Commands;

use App\Enums\InviteStatus;
use App\Models\OrganizationInvite;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class PruneRecordsCommand extends Command
{
    private const KEEP_DAYS = 7;

    protected $signature = 'records:prune';

    protected $description = 'Delete the notifications read more than 7 days ago and the invitations finished more than 7 days ago';

    public function handle(): int
    {
        $limit = now()->subDays(self::KEEP_DAYS);

        $notifications = $this->pruneReadNotifications($limit);
        $this->comment("Deleted {$notifications} read ".Str::plural('notification', $notifications).'.');

        $invitations = $this->pruneFinishedInvitations($limit);
        $this->comment("Deleted {$invitations} finished ".Str::plural('invitation', $invitations).'.');

        return self::SUCCESS;
    }

    /**
     * Unread notifications are never deleted. Deleting a read one cannot make a task reminder send twice:
     * `SendTaskRemindersCommand::alreadyNotified()` keys on the organization's local date.
     */
    private function pruneReadNotifications(CarbonInterface $limit): int
    {
        return DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $limit)
            ->delete();
    }

    /**
     * Pending invitations are never deleted, even past `expires_at`: the invitations list still shows them.
     * `updated_at` is the day the status changed to its final value.
     */
    private function pruneFinishedInvitations(CarbonInterface $limit): int
    {
        return OrganizationInvite::query()
            ->whereIn('status', [InviteStatus::Accepted, InviteStatus::Revoked, InviteStatus::Expired])
            ->where('updated_at', '<', $limit)
            ->delete();
    }
}
