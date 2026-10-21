<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum ReportPeriodPreset: string implements HasLabel
{
    case Today = 'today';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case LastQuarter = 'last_quarter';
    case ThisYear = 'this_year';
    case Last12Months = 'last_12_months';
    case Custom = 'custom';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Today => 'Today',
            self::ThisWeek => 'This week',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::LastQuarter => 'Last quarter',
            self::ThisYear => 'This year',
            self::Last12Months => 'Last 12 months',
            self::Custom => 'Custom range',
        };
    }
}
