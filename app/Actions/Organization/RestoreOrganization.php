<?php

namespace App\Actions\Organization;

use App\Enums\OrganizationRole;
use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Security\SecurityLog;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Restores an organization during its grace period (SEC-52), on the operator's request from the shell
 * (`organizations:restore`). Everything `DeleteOrganization` left in place comes back; revoked invitations and the
 * deleted export files do not.
 */
class RestoreOrganization
{
    /**
     * Returns the refusal sentence, or null when the organization can be restored. `$organization` is the row read with
     * `withTrashed()` (null when it does not exist any more).
     */
    public function refusalFor(int $organizationId, ?Organization $organization): ?string
    {
        if ($organization === null) {
            return "No organization #{$organizationId} exists: it was never created or has already been purged.";
        }

        if (! $organization->trashed()) {
            return "Organization #{$organizationId} \"{$organization->name}\" is not deleted: there is nothing to restore.";
        }

        $hasOwner = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner->value)
            ->exists();

        if (! $hasOwner) {
            return "Organization #{$organizationId} \"{$organization->name}\" has no owner any more (the owner deleted their account), so it cannot be restored.";
        }

        return null;
    }

    /**
     * Returns the refusal sentence (nothing is written), or null once the organization is restored. The checks run
     * again on the row read with a lock, so a restore, a purge or an account deletion racing the command wins over
     * the pre-check.
     */
    public function handle(int $organizationId): ?string
    {
        return DB::transaction(function () use ($organizationId): ?string {
            $locked = Organization::withTrashed()->lockForUpdate()->find($organizationId);

            $refusal = $this->refusalFor($organizationId, $locked);

            if ($refusal !== null || $locked === null) {
                return $refusal;
            }

            $locked->restore();

            $members = $locked->members()->get();
            $name = $locked->name;

            SecurityLog::warning('organization.restored', null, [
                'restored_organization_id' => $organizationId,
                'members' => $members->count(),
            ]);

            $segmentIds = Segment::forOrganization($organizationId)->where('is_published', true)->pluck('id');

            DB::afterCommit(function () use ($segmentIds, $name, $members): void {
                $segmentIds->each(fn (int $segmentId) => SyncSegmentMembership::dispatch($segmentId));

                $members->each(fn (User $member) => Notification::make()
                    ->success()
                    ->title(NotificationText::plain($name.' was restored'))
                    ->body(NotificationText::plain('This organization was restored. You can use it again.'))
                    ->sendToDatabase($member));
            });

            return null;
        });
    }
}
