<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The currencies an organization or an invoice can use.
 */
enum Currency: string implements HasColor, HasIcon, HasLabel
{
    case Usd = 'USD';
    case Eur = 'EUR';
    case Gbp = 'GBP';
    case Cad = 'CAD';
    case Aud = 'AUD';
    case Tnd = 'TND';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Usd => 'US dollar (USD)',
            self::Eur => 'Euro (EUR)',
            self::Gbp => 'British pound (GBP)',
            self::Cad => 'Canadian dollar (CAD)',
            self::Aud => 'Australian dollar (AUD)',
            self::Tnd => 'Tunisian dinar (TND)',
        };
    }

    public function getColor(): string|array|null
    {
        return 'gray';
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedBanknotes;
    }
}
