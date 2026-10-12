<?php

namespace App\Filament\Support;

use App\Enums\Currency;
use Filament\Forms\Components\Select;

/**
 * The currency field of the organization forms.
 */
class OrganizationCurrencySelect
{
    public static function make(): Select
    {
        return Select::make('currency')
            ->label('Currency')
            ->options(Currency::class)
            ->required()
            ->default(Currency::Usd)
            ->helperText('Used for every deal value and invoice amount. Changing it does not convert existing amounts.');
    }
}
