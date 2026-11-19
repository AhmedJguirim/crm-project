<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Support\StateCasts\RecordKeyStateCast;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a Filament resource answer 404 for a `{record}` URL segment that is not a readable id (BUG-78). A non-id
 * segment (`abc`, `1.5`, a 20-digit number) must be refused before PostgreSQL sees it, because a bigint column
 * refuses the query and the page would answer 500.
 */
trait ResolvesReadableRecordKeys
{
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $readableKey = RecordKeyStateCast::toKey($key);

        if ($readableKey === null) {
            return null;
        }

        return parent::resolveRecordRouteBinding($readableKey, $modifyQuery);
    }
}
