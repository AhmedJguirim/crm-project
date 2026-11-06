<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The version of the Terms of Service and Privacy Policy in force (the ISO date of their "Last updated:" line),
 * and the user attributes that record its acceptance. Bump VERSION whenever either document's date changes.
 */
final class LegalDocuments
{
    public const string VERSION = '2026-11-06';

    /**
     * @return array{terms_accepted_at: Carbon, terms_version: string}
     */
    public static function acceptance(): array
    {
        return [
            'terms_accepted_at' => now(),
            'terms_version' => self::VERSION,
        ];
    }
}
