<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Every member of an organization can manage its segments until roles and permissions are introduced (FEAT-01):
 * change the rules here, and the segment pages and the rule editor follow.
 */
class SegmentPolicy
{
    /** @var array<string, bool> Memberships already looked up in this request, as a table asks once per row. */
    private array $memberships = [];

    public function viewAny(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user);
    }

    public function view(User $user, Segment $segment): bool
    {
        return $this->isMemberOf($user, $segment->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user);
    }

    public function update(User $user, Segment $segment): bool
    {
        return $this->isMemberOf($user, $segment->organization_id);
    }

    public function delete(User $user, Segment $segment): bool
    {
        return $this->isMemberOf($user, $segment->organization_id);
    }

    private function isMemberOf(User $user, int $organizationId): bool
    {
        return $this->memberships["{$user->getKey()}:{$organizationId}"] ??= Organization::query()->find($organizationId)?->hasMember($user) ?? false;
    }

    private function belongsToCurrentOrganization(User $user): bool
    {
        $organization = Filament::getTenant();

        return $organization instanceof Organization && $organization->hasMember($user);
    }
}
