<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum ContactStatus: string implements HasColor, HasIcon, HasLabel
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case ActiveClient = 'active_client';
    case PastClient = 'past_client';
    case Partner = 'partner';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Prospect => 'Prospect',
            self::ActiveClient => 'Active Client',
            self::PastClient => 'Past Client',
            self::Partner => 'Partner',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Lead => 'gray',
            self::Prospect => 'info',
            self::ActiveClient => 'success',
            self::PastClient => 'warning',
            self::Partner => 'purple',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Lead => Heroicon::OutlinedUser,
            self::Prospect => Heroicon::OutlinedMagnifyingGlass,
            self::ActiveClient => Heroicon::OutlinedBriefcase,
            self::PastClient => Heroicon::OutlinedArchiveBox,
            self::Partner => Heroicon::OutlinedHandRaised,
        };
    }
}
