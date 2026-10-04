<?php

namespace App\Policies;

use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Importing contacts is for admins and the owner.
 */
class ContactPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'import'];

    public function import(User $user): bool
    {
        return $this->can($user, 'import', null);
    }
}
