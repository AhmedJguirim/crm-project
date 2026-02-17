<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $organization->isOwner($user);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $organization->isOwner($user) && ! $organization->personal_team;
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $organization->isAdminOrOwner($user);
    }

    public function transferOwnership(User $user, Organization $organization): bool
    {
        return $organization->isOwner($user);
    }

    public function removeMember(User $user, Organization $organization, User $member): bool
    {
        if ($member->id === $user->id) {
            return false;
        }

        if ($organization->isOwner($member)) {
            return false;
        }

        return $organization->isAdminOrOwner($user);
    }

    public function changeRole(User $user, Organization $organization, User $member): bool
    {
        if ($organization->isOwner($member)) {
            return false;
        }

        return $organization->isAdminOrOwner($user);
    }
}
