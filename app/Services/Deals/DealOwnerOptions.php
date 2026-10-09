<?php

namespace App\Services\Deals;

use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;

final class DealOwnerOptions
{
    /**
     * The users a deal can be given to: the current members of the organization, plus the deal's own owner when they are no longer a member.
     *
     * @return array<int, string>
     */
    public static function forForm(?Deal $deal): array
    {
        $options = self::members();

        if ($deal?->owner_id !== null && ! array_key_exists($deal->owner_id, $options)) {
            $options[$deal->owner_id] = $deal->ownerLabel() ?? '';
        }

        return $options;
    }

    /**
     * The users the deals table can be filtered by: the current members, plus every former member who still owns a deal of the organization.
     *
     * @return array<int, string>
     */
    public static function forFilter(): array
    {
        $options = self::members();
        $organizationId = Filament::getTenant()?->getKey();

        $former = User::query()
            ->whereIn('id', Deal::query()->where('organization_id', $organizationId)->whereNotNull('owner_id')->select('owner_id'))
            ->whereNotIn('id', array_keys($options))
            ->orderBy('name')
            ->pluck('name', 'id');

        foreach ($former as $id => $name) {
            $options[$id] = "{$name} (no longer a member)";
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function members(): array
    {
        return Filament::getTenant()?->members()
            ->orderBy('users.name')
            ->pluck('users.name', 'users.id')
            ->all() ?? [];
    }
}
