<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use Illuminate\Database\Eloquent\Builder;

interface SegmentConditionCompiler
{
    /**
     * Constrain the contacts query to the contacts matching the (complete) condition.
     */
    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void;
}
