<?php

namespace App\Models\Concerns;

use App\Exceptions\UsedInSegmentsException;
use App\Services\Segments\SegmentUsage;

/**
 * Records referenced by segment conditions cannot be deleted (not even soft-deleted), as that would break the rules.
 */
trait PreventsDeletionWhileUsedInSegments
{
    public static function bootPreventsDeletionWhileUsedInSegments(): void
    {
        static::deleting(function (self $model): void {
            $segments = SegmentUsage::segmentsUsing($model);

            if ($segments->isNotEmpty()) {
                throw UsedInSegmentsException::cannotDelete($model, $segments);
            }
        });
    }
}
