<?php

namespace App\Support\Reports;

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;

/**
 * The deal list URL showing the deals a won / lost / created stat counts: the status (won / lost), closed or created in
 * the stat's period and, when the page owner filter is set, the owner. Built by hand: `http_build_query` would
 * percent-encode the brackets.
 */
final class DealListLink
{
    public static function won(DashboardFilters $filters): string
    {
        return self::closed(DealStatus::Won, $filters);
    }

    public static function lost(DashboardFilters $filters): string
    {
        return self::closed(DealStatus::Lost, $filters);
    }

    private static function closed(DealStatus $status, DashboardFilters $filters): string
    {
        [$startDate, $endDate] = $filters->period->dates();

        return self::withOwner(DealResource::getUrl('index').'?filters[status][value]='.$status->value
            .'&filters[closed_between][from]='.$startDate
            .'&filters[closed_between][until]='.$endDate, $filters);
    }

    public static function created(DashboardFilters $filters): string
    {
        [$startDate, $endDate] = $filters->period->dates();

        return self::withOwner(DealResource::getUrl('index').'?filters[created_between][from]='.$startDate
            .'&filters[created_between][until]='.$endDate, $filters);
    }

    private static function withOwner(string $url, DashboardFilters $filters): string
    {
        if ($filters->ownerId === null) {
            return $url;
        }

        return $url.'&filters[owner_id][value]='.$filters->ownerId;
    }
}
