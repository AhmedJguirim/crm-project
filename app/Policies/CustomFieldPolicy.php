<?php

namespace App\Policies;

/**
 * Custom fields and company types shape every record of the organization, so changing them needs an admin or the
 * owner, like deleting them. See TenantModelPolicy.
 */
class CustomFieldPolicy extends TenantModelPolicy
{
    protected array $editAbilities = [];

    protected array $manageAbilities = ['create', 'update', 'updateAny', 'replicate', 'reorder', 'delete', 'restore', 'deleteAny', 'restoreAny'];
}
