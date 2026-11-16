<?php

namespace App\Actions\Organization;

use App\Enums\InviteStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Security\SecurityLog;
use App\Support\UserFiles;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an organization on its Owner's request (SEC-51): a soft delete that keeps every row for the grace period
 * (`Organization::DELETION_GRACE_DAYS`), after which `organizations:purge` removes it for good.
 */
class DeleteOrganization
{
    /**
     * Returns the refusal sentence (nothing is written), or null once the organization is deleted. The organization is
     * re-read with a lock and the owner is checked again under it, so an ownership transfer or a second submit made
     * in another tab wins over a stale modal.
     */
    public function handle(User $user, Organization $organization): ?string
    {
        return DB::transaction(function () use ($user, $organization): ?string {
            $locked = Organization::query()->lockForUpdate()->find($organization->getKey());

            if ($locked === null) {
                return 'This organization was already deleted.';
            }

            if (! $locked->isOwner($user) || $locked->personal_team) {
                return 'Only the owner can delete this organization.';
            }

            $locked->invitations()
                ->where('status', InviteStatus::Pending)
                ->get()
                ->each->revoke();

            $others = $locked->members()->where('users.id', '!=', $user->getKey())->get();

            $locked->delete();

            SecurityLog::warning('organization.deleted', $user, [
                'deleted_organization_id' => $locked->getKey(),
                'members' => $others->count(),
            ]);

            $organizationId = $locked->getKey();
            $name = $locked->name;

            DB::afterCommit(function () use ($organizationId, $name, $user, $others): void {
                UserFiles::deleteForOrganization($organizationId);

                $others->each(fn (User $member) => Notification::make()
                    ->danger()
                    ->title(NotificationText::plain($name.' was deleted'))
                    ->body(NotificationText::plain($user->name.' deleted this organization. You no longer have access to it.'))
                    ->sendToDatabase($member));
            });

            return null;
        });
    }
}
