<?php

namespace App\Filament\Support;

use App\Models\Organization;
use Filament\Forms\Components\Select;

/**
 * The timezone field of the organization forms.
 */
class OrganizationTimezoneSelect
{
    public static function make(): Select
    {
        $identifiers = Organization::timezoneIdentifiers();

        return Select::make('timezone')
            ->options(array_combine($identifiers, $identifiers))
            ->searchable()
            ->required()
            ->rules(['timezone:all'])
            ->default('UTC')
            ->helperText('Used for date-based segments, task reminders and the daily digest.');
    }
}
