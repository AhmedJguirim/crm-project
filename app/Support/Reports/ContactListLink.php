<?php

namespace App\Support\Reports;

use App\Enums\ContactStatus;
use App\Filament\Resources\Contacts\ContactResource;

/**
 * The contact list URL showing the contacts a dashboard contact / lead stat counts: created in the stat's period, the
 * Lead status for the lead stats and, when the page owner filter is set, the owner. Built by hand: `http_build_query`
 * would percent-encode the brackets.
 */
final class ContactListLink
{
    public static function created(DashboardFilters $filters): string
    {
        return self::withOwner(ContactResource::getUrl('index').'?'.self::period($filters), $filters);
    }

    public static function newLeads(DashboardFilters $filters): string
    {
        return self::withOwner(ContactResource::getUrl('index').'?'.self::lead().'&'.self::period($filters), $filters);
    }

    public static function openLeads(DashboardFilters $filters): string
    {
        return self::withOwner(ContactResource::getUrl('index').'?'.self::lead(), $filters);
    }

    private static function lead(): string
    {
        return 'filters[status][values][0]='.ContactStatus::Lead->value;
    }

    private static function period(DashboardFilters $filters): string
    {
        [$startDate, $endDate] = $filters->period->dates();

        return 'filters[created_between][from]='.$startDate.'&filters[created_between][until]='.$endDate;
    }

    private static function withOwner(string $url, DashboardFilters $filters): string
    {
        if ($filters->ownerId === null) {
            return $url;
        }

        return $url.'&filters[owner_id][value]='.$filters->ownerId;
    }
}
