<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum TaskType: string implements HasColor, HasIcon, HasLabel
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case FollowUp = 'follow_up';
    case Invoice = 'invoice';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::FollowUp => 'Follow-up',
            self::Invoice => 'Invoice',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Call => 'info',
            self::Email => 'primary',
            self::Meeting => 'warning',
            self::FollowUp => 'success',
            self::Invoice => 'danger',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Call => Heroicon::OutlinedPhone,
            self::Email => Heroicon::OutlinedEnvelope,
            self::Meeting => Heroicon::OutlinedUserGroup,
            self::FollowUp => Heroicon::OutlinedArrowPathRoundedSquare,
            self::Invoice => Heroicon::OutlinedReceiptPercent,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }
}
