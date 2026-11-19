<?php

namespace App\Filament\Support;

use App\Enums\Currency;
use App\Models\Organization;
use Closure;
use Filament\Forms\Components\Select;

/**
 * The currency field of the organization forms. Locked on the settings page once an invoice has been issued (FEAT-142).
 */
class OrganizationCurrencySelect
{
    public static function make(?Organization $organization = null): Select
    {
        $locked = fn (): bool => $organization?->hasIssuedInvoices() ?? false;

        return Select::make('currency')
            ->label('Currency')
            ->options(Currency::class)
            ->required()
            ->default(Currency::Usd)
            ->disabled($locked)
            ->helperText(fn (): string => $locked()
                ? 'Used for every deal value and invoice amount. It can\'t be changed once an invoice has been issued, because each issued invoice keeps its exchange rate to this currency.'
                : 'Used for every deal value and invoice amount. Changing it does not convert existing amounts.')
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
                    $chosen = $value instanceof Currency ? $value->value : (is_string($value) ? $value : null);

                    if ($organization === null || $chosen === null || $chosen === $organization->currencyCode()) {
                        return;
                    }

                    if ($organization->hasIssuedInvoices()) {
                        $fail("The currency can't be changed once an invoice has been issued: each issued invoice keeps its exchange rate to {$organization->currencyCode()}.");
                    }
                },
            ]);
    }
}
