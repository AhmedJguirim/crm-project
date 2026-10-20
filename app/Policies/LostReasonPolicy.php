<?php

namespace App\Policies;

/**
 * The lost reasons are offered on every lost deal of the organization, so changing them needs an admin or the owner;
 * every role can see them. See TenantModelPolicy.
 */
class LostReasonPolicy extends TenantModelPolicy
{
    protected array $editAbilities = [];

    protected array $manageAbilities = ['create', 'update', 'updateAny', 'replicate', 'reorder', 'delete', 'restore', 'deleteAny', 'restoreAny'];
}
