<?php

namespace App\Policies;

use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Exporting tasks is for admins and the owner.
 */
class TaskPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'export'];

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }
}
