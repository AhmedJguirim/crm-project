<?php

namespace App\Policies;

use App\Models\Segment;
use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Members create segments and edit their rules (as a draft once a
 * segment is published); publishing a segment and saving its draft are for admins and the owner.
 */
class SegmentPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'publish'];

    public function publish(User $user, Segment $segment): bool
    {
        return $this->can($user, 'publish', $segment);
    }
}
