<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The currencies an organization can work in.
 */
enum Currency: string implements HasLabel
{
    case Usd = 'USD';
    case Eur = 'EUR';
    case Gbp = 'GBP';
    case Cad = 'CAD';
    case Aud = 'AUD';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Usd => 'US dollar (USD)',
            self::Eur => 'Euro (EUR)',
            self::Gbp => 'British pound (GBP)',
            self::Cad => 'Canadian dollar (CAD)',
            self::Aud => 'Australian dollar (AUD)',
        };
    }
}
