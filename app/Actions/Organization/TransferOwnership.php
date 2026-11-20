<?php

namespace App\Actions\Organization;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Hands an organization over to another member (BUG-77). The organization row is locked `FOR UPDATE` (the same lock
 * as `DeleteUserAccount` and `DeleteOrganization`), and both roles are read again under it, so a leave, a removal,
 * a role change or a second transfer made in another tab can't leave the organization without an Owner.
 *
 * The new Owner is promoted first: when that changes no row nothing has been written yet. The demotion that follows
 * can only fail if the lock was bypassed, and then the exception rolls the promotion back.
 */
final class TransferOwnership
{
    /**
     * Returns the refusal sentence (nothing is written), or null once the ownership is transferred.
     */
    public function handle(User $currentOwner, Organization $organization, int $newOwnerId): ?string
    {
        return DB::transaction(function () use ($currentOwner, $organization, $newOwnerId): ?string {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return 'This organization was deleted.';
            }

            if ($this->roleOf($locked, $currentOwner->id) !== OrganizationRole::Owner->value) {
                return 'You are no longer the owner of this organization.';
            }

            $notMember = 'The member you chose is no longer in this organization. Choose another member.';

            if ($newOwnerId === $currentOwner->id || $this->roleOf($locked, $newOwnerId) === null) {
                return $notMember;
            }

            $promoted = $locked->members()->updateExistingPivot($newOwnerId, ['role' => OrganizationRole::Owner->value]);

            if ($promoted !== 1) {
                return $notMember;
            }

            $demoted = $locked->members()->updateExistingPivot($currentOwner->id, ['role' => OrganizationRole::Admin->value]);

            if ($demoted !== 1) {
                throw new \LogicException('Ownership transfer demoted 0 rows.');
            }

            SecurityLog::warning('organization.ownership_transferred', $currentOwner, [
                'new_owner_user_id' => $newOwnerId,
            ]);

            return null;
        });
    }

    private function roleOf(Organization $organization, int $userId): ?string
    {
        $role = DB::table('organization_user')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->value('role');

        return $role === null ? null : (string) $role;
    }
}
