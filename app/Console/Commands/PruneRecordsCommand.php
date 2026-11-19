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

    private const UNREAD_NOTIFICATION_KEEP_DAYS = 90;

    private const EXPIRED_INVITATION_KEEP_DAYS = 7;

    protected $signature = 'records:prune';

    protected $description = 'Delete the notifications read more than 7 days ago or unread for more than 90 days, and the invitations finished more than 7 days ago or pending 7 days past their expiry';

    public function handle(): int
    {
        $limit = now()->subDays(self::KEEP_DAYS);

        $notifications = $this->pruneReadNotifications($limit);
        $this->comment("Deleted {$notifications} read ".Str::plural('notification', $notifications).'.');

        $invitations = $this->pruneFinishedInvitations($limit);
        $this->comment("Deleted {$invitations} finished ".Str::plural('invitation', $invitations).'.');

        $unread = $this->pruneOldUnreadNotifications(now()->subDays(self::UNREAD_NOTIFICATION_KEEP_DAYS));
        $this->comment("Deleted {$unread} unread ".Str::plural('notification', $unread).' older than 90 days.');

        $expired = $this->pruneExpiredPendingInvitations(now()->subDays(self::EXPIRED_INVITATION_KEEP_DAYS));
        $this->comment("Deleted {$expired} expired pending ".Str::plural('invitation', $expired).'.');

        return self::SUCCESS;
    }

    /**
     * Unread notifications older than 90 days are deleted by `pruneOldUnreadNotifications()`. Deleting a read one cannot
     * make a task reminder send twice: `SendTaskRemindersCommand::alreadyNotified()` keys on the organization's local date.
     */
    private function pruneReadNotifications(CarbonInterface $limit): int
    {
        return DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $limit)
            ->delete();
    }

    /**
     * Pending invitations are deleted 7 days after `expires_at` by `pruneExpiredPendingInvitations()`.
     * `updated_at` is the day the status changed to its final value.
     */
    private function pruneFinishedInvitations(CarbonInterface $limit): int
    {
        return OrganizationInvite::query()
            ->whereIn('status', [InviteStatus::Accepted, InviteStatus::Revoked, InviteStatus::Expired])
            ->where('updated_at', '<', $limit)
            ->delete();
    }

    /**
     * Pruning an old unread notification cannot resend a task reminder either: `SendTaskRemindersCommand` keys on
     * `due-soon:{task}:{local date}` / `overdue:{task}:{due instant}` and only selects tasks newly due soon or newly
     * overdue since the previous hourly run.
     */
    private function pruneOldUnreadNotifications(CarbonInterface $limit): int
    {
        return DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('created_at', '<', $limit)
            ->delete();
    }

    /**
     * A pending invitation less than 7 days past `expires_at` stays: the list still shows it and an admin can resend it,
     * which moves `expires_at` to 7 days ahead.
     */
    private function pruneExpiredPendingInvitations(CarbonInterface $limit): int
    {
        return OrganizationInvite::query()
            ->where('status', InviteStatus::Pending)
            ->where('expires_at', '<', $limit)
            ->delete();
    }
}
