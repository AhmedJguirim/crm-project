<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the other contacts of the organization a contact form should warn about: the same phone number or the same
 * name working for one of the same companies. Only used for a hint, never to block a save.
 */
final class PossibleDuplicateContacts
{
    public const PHONE_MIN_DIGITS = 6;

    /**
     * The ASCII digits of a typed phone number, or null when it is not text or has too few digits to compare.
     */
    public static function phoneDigits(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === null || strlen($digits) < self::PHONE_MIN_DIGITS) {
            return null;
        }

        return $digits;
    }

    /**
     * The other contacts with the same phone digits, or null when the typed value cannot be compared.
     *
     * @return Builder<Contact>|null
     */
    public static function withSamePhone(mixed $phone, ?Contact $except): ?Builder
    {
        $digits = self::phoneDigits($phone);

        if ($digits === null) {
            return null;
        }

        return self::candidates($except)
            ->whereRaw("regexp_replace(contacts.phone, '[^0-9]', '', 'g') = ?", [$digits]);
    }

    /**
     * The other contacts with the same name (ignoring case and outer spaces) working for at least one of the given
     * companies, or null when the name is blank or no valid company id is given.
     *
     * @return Builder<Contact>|null
     */
    public static function withSameNameAndCompany(mixed $name, mixed $companyIds, ?Contact $except): ?Builder
    {
        if (! is_string($name) || ! is_array($companyIds)) {
            return null;
        }

        $name = mb_strtolower(trim($name));

        $ids = collect($companyIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();

        if ($name === '' || $ids === []) {
            return null;
        }

        return self::candidates($except)
            ->whereRaw('lower(trim(contacts.name)) = ?', [$name])
            ->whereHas('companies', fn (Builder $query): Builder => $query->whereKey($ids));
    }

    /**
     * @return Builder<Contact>
     */
    private static function candidates(?Contact $except): Builder
    {
        return Contact::query()
            ->when($except?->exists, fn (Builder $query): Builder => $query->whereKeyNot($except->getKey()));
    }
}
