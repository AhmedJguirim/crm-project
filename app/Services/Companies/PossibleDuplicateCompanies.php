<?php

namespace App\Services\Companies;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the other companies of the organization a company form should warn about: the same name, ignoring case and
 * spacing. Only used for a hint, never to block a save.
 */
final class PossibleDuplicateCompanies
{
    /**
     * The typed name with every run of whitespace collapsed to one space, trimmed and lower-cased, or null when it is
     * not text or is blank.
     */
    public static function normalizedName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The other non-trashed companies of the current organization with the same normalised name, or null when the
     * typed value cannot be compared.
     *
     * @return Builder<Company>|null
     */
    public static function withSameName(mixed $name, ?Company $except): ?Builder
    {
        $normalized = self::normalizedName($name);

        if ($normalized === null) {
            return null;
        }

        return Company::query()
            ->when($except?->exists, fn (Builder $query): Builder => $query->whereKeyNot($except->getKey()))
            ->whereRaw("lower(trim(regexp_replace(companies.name, '\s+', ' ', 'g'))) = ?", [$normalized]);
    }
}
