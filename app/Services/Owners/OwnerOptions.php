<?php

namespace App\Services\Owners;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

final class OwnerOptions
{
    /**
     * The users a deal, task, contact or company can be given to: the current members of the organization, plus the record's own owner when they are no longer a member.
     *
     * @return array<int, string>
     */
    public static function forForm(Deal|Task|Contact|Company|null $record): array
    {
        $options = self::members();

        if ($record?->owner_id !== null && ! array_key_exists($record->owner_id, $options)) {
            $options[$record->owner_id] = $record->ownerLabel() ?? '';
        }

        return $options;
    }

    /**
     * The users a table of deals, tasks, contacts or companies can be filtered by: the current members, plus every former member who still owns a record of the organization.
     *
     * @param  class-string<Deal|Task|Contact|Company>  $modelClass
     * @return array<int, string>
     */
    public static function forFilter(string $modelClass): array
    {
        $options = self::members();
        $organizationId = CurrentOrganization::find()?->getKey();

        $former = User::query()
            ->whereIn('id', $modelClass::query()->where('organization_id', $organizationId)->whereNotNull('owner_id')->select('owner_id'))
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
        return CurrentOrganization::find()?->members()
            ->orderBy('users.name')
            ->pluck('users.name', 'users.id')
            ->all() ?? [];
    }
}
