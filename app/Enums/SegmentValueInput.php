<?php

namespace App\Enums;

/**
 * The shape of the value a segment condition operator expects.
 */
enum SegmentValueInput: string
{
    case None = 'none';
    case Single = 'single';
    case Range = 'range';
    case Multiple = 'multiple';
    case Days = 'days';
    case Month = 'month';
    case DayAndMonth = 'day_and_month';
    case ActivityCriteria = 'activity_criteria';
    case DealCriteria = 'deal_criteria';
}
