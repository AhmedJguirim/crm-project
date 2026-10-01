<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum SegmentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Syncing = 'syncing';
    case Published = 'published';
    case PendingChanges = 'pending_changes';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Not published',
            self::Syncing => 'Syncing',
            self::Published => 'Published',
            self::PendingChanges => 'Unsaved changes',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Syncing => 'info',
            self::Published => 'success',
            self::PendingChanges => 'warning',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil,
            self::Syncing => Heroicon::OutlinedArrowPath,
            self::Published => Heroicon::OutlinedCheckCircle,
            self::PendingChanges => Heroicon::OutlinedExclamationTriangle,
        };
    }
}
