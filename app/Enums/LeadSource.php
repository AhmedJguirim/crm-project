<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum LeadSource: string implements HasColor, HasIcon, HasLabel
{
    case Referral = 'referral';
    case LinkedIn = 'linkedin';
    case Upwork = 'upwork';
    case ColdOutreach = 'cold_outreach';
    case Conference = 'conference';
    case Website = 'website';
    case SocialMedia = 'social_media';
    case Inbound = 'inbound';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Referral => 'Referral',
            self::LinkedIn => 'LinkedIn',
            self::Upwork => 'Upwork',
            self::ColdOutreach => 'Cold Outreach',
            self::Conference => 'Conference',
            self::Website => 'Website',
            self::SocialMedia => 'Social Media',
            self::Inbound => 'Inbound',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Referral => 'success',
            self::LinkedIn => 'info',
            self::Upwork => 'warning',
            self::ColdOutreach => 'gray',
            self::Conference => 'primary',
            self::Website => 'sky',
            self::SocialMedia => 'pink',
            self::Inbound => 'emerald',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Referral => Heroicon::OutlinedUsers,
            self::LinkedIn => Heroicon::OutlinedGlobeAlt,
            self::Upwork => Heroicon::OutlinedBriefcase,
            self::ColdOutreach => Heroicon::OutlinedEnvelope,
            self::Conference => Heroicon::OutlinedBuildingOffice,
            self::Website => Heroicon::OutlinedComputerDesktop,
            self::SocialMedia => Heroicon::OutlinedMegaphone,
            self::Inbound => Heroicon::OutlinedArrowDownCircle,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }
}
