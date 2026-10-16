<?php

namespace App\Policies;

/**
 * The stages of the pipeline shape every deal of the organization, so changing them needs an admin or the owner;
 * every role can see them. See TenantModelPolicy.
 */
class PipelineStagePolicy extends TenantModelPolicy
{
    protected array $editAbilities = [];

    protected array $manageAbilities = ['create', 'update', 'updateAny', 'replicate', 'reorder', 'delete', 'restore', 'deleteAny', 'restoreAny'];
}
