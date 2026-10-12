<?php

namespace App\Services\Deals;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

final class DealCompany
{
    /**
     * The id of the only live company of a contact; null when the contact is blank, missing or trashed, or has no
     * company or several (there is no primary company, so an ambiguous contact leaves the company empty).
     */
    public static function defaultIdFor(int|string|null $contactId): ?int
    {
        if (blank($contactId)) {
            return null;
        }

        $ids = Company::query()
            ->whereHas('contacts', fn (Builder $query): Builder => $query->whereKey($contactId))
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
