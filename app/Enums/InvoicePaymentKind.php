<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum InvoicePaymentKind: string implements HasColor, HasIcon, HasLabel
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Refund => 'Refund',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Payment => 'success',
            self::Refund => 'danger',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Payment => Heroicon::OutlinedArrowDownLeft,
            self::Refund => Heroicon::OutlinedArrowUturnLeft,
        };
    }
}
