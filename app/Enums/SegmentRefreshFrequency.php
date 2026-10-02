<?php

namespace App\Enums;

/**
 * How often a segment whose conditions depend on the current time is recomputed on a schedule.
 */
enum SegmentRefreshFrequency: string
{
    case Daily = 'daily';
    case Hourly = 'hourly';

    public function isMoreFrequentThan(self $other): bool
    {
        return $this === self::Hourly && $other === self::Daily;
    }
}
