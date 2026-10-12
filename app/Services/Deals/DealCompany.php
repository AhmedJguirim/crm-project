<?php

namespace App\Services\Deals;

use App\Filament\Support\StateCasts\RecordKeyStateCast;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

final class DealCompany
{
    /**
     * The id of the only live company of a contact; null when the contact is blank, not an id, missing or trashed, or
     * has no company or several (there is no primary company, so an ambiguous contact leaves the company empty).
     */
    public static function defaultIdFor(int|string|null $contactId): ?int
    {
        if (blank($contactId)) {
            return null;
        }

        $id = RecordKeyStateCast::toKey($contactId);

        if ($id === null) {
            return null;
        }

        $ids = Company::query()
            ->whereHas('contacts', fn (Builder $query): Builder => $query->whereKey($id))
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
