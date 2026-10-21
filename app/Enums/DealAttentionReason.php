<?php

namespace App\Enums;

use App\Services\Reports\DealsNeedingAttention;
use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Why an open deal needs attention; the case order is the badge order.
 */
enum DealAttentionReason: string implements HasColor, HasIcon, HasLabel
{
    case PastCloseDate = 'past_close_date';
    case NoNextStep = 'no_next_step';
    case NoRecentActivity = 'no_recent_activity';
    case StuckInStage = 'stuck_in_stage';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PastCloseDate => 'Past close date',
            self::NoNextStep => 'No next step',
            self::NoRecentActivity => 'No activity for '.DealsNeedingAttention::INACTIVITY_DAYS.' days',
            self::StuckInStage => 'Same stage for '.DealsNeedingAttention::STAGE_DAYS.'+ days',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PastCloseDate => 'danger',
            self::NoNextStep => 'warning',
            self::NoRecentActivity => 'gray',
            self::StuckInStage => 'info',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::PastCloseDate => Heroicon::OutlinedCalendarDays,
            self::NoNextStep => Heroicon::OutlinedFlag,
            self::NoRecentActivity => Heroicon::OutlinedChatBubbleLeftRight,
            self::StuckInStage => Heroicon::OutlinedClock,
        };
    }
}
