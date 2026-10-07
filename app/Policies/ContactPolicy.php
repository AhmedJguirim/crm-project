<?php

namespace App\Policies;

use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Importing and exporting contacts is for admins and the owner.
 */
class ContactPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'import', 'export'];

    public function import(User $user): bool
    {
        return $this->can($user, 'import', null);
    }

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }
}
