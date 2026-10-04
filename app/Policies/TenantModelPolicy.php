<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Who can do what with the records of an organization, from the role of the user in it:
 *
 * - viewer: sees everything;
 * - member: also creates and updates (the abilities of `$editAbilities`);
 * - admin and owner: also deletes and restores, and does what is organization-wide (the abilities of
 *   `$manageAbilities`).
 *
 * Forcing a deletion is refused to everyone. The abilities without a record (`viewAny`, `create`, the "any" ones)
 * look at the organization being worked in, and the ones with a record at the organization of the record: a user who is
 * not a member of it can do nothing. A policy changes the two lists to move an ability to another level, and adds its
 * own abilities with `can()`.
 */
abstract class TenantModelPolicy
{
    /** @var array<int, string> The abilities for which a member is enough. */
    protected array $editAbilities = ['create', 'update', 'updateAny', 'replicate', 'reorder'];

    /** @var array<int, string> The abilities that need an admin or the owner. */
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny'];

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny', null);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->can($user, 'view', $record);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create', null);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->can($user, 'update', $record);
    }

    public function updateAny(User $user): bool
    {
        return $this->can($user, 'updateAny', null);
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->can($user, 'replicate', $record);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'reorder', null);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->can($user, 'delete', $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'deleteAny', null);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->can($user, 'restore', $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'restoreAny', null);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * The role of the user in the organization of the record, or in the current one when there is no record.
     */
    protected function roleOf(User $user, ?Model $record): ?OrganizationRole
    {
        $organizationId = $record === null
            ? app(TenantContext::class)->id()
            : $record->getAttribute('organization_id');

        return $organizationId === null ? null : $user->roleIn((int) $organizationId);
    }

    protected function can(User $user, string $ability, ?Model $record): bool
    {
        $role = $this->roleOf($user, $record);

        return match (true) {
            $role === null => false,
            in_array($ability, $this->manageAbilities, true) => $role->canManage(),
            in_array($ability, $this->editAbilities, true) => $role->canEdit(),
            default => $role->canView(),
        };
    }
}
