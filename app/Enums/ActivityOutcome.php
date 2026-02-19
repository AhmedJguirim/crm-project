<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActivityOutcome: string implements HasColor, HasLabel
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case FollowUpNeeded = 'follow_up_needed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Positive => 'Positive',
            self::Neutral => 'Neutral',
            self::Negative => 'Negative',
            self::FollowUpNeeded => 'Follow-up Needed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Positive => 'success',
            self::Neutral => 'gray',
            self::Negative => 'danger',
            self::FollowUpNeeded => 'warning',
        };
    }
}
