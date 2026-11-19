<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * See TenantModelPolicy for what each role can do. Importing, exporting and merging contacts is for admins and the owner.
 * A merged contact can never be restored: the merge is the audit trail and there is no unmerge.
 */
class ContactPolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'import', 'export', 'merge'];

    public function import(User $user): bool
    {
        return $this->can($user, 'import', null);
    }

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }

    public function merge(User $user, Model $record): bool
    {
        return $this->can($user, 'merge', $record);
    }

    public function restore(User $user, Model $record): bool
    {
        if ($record->getAttribute('merged_into_id') !== null) {
            return false;
        }

        return parent::restore($user, $record);
    }
}
