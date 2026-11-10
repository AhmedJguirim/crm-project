<?php

namespace App\Policies;

use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Exporting activities is for admins and the owner.
 */
class ActivityPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'export'];

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }
}
