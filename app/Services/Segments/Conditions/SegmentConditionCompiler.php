<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every fragment an implementation inlines into SQL is a constant, a grammar-wrapped identifier, or a value validated
 * in the implementation itself (enum, anchored `\z` regex, allow-list). User values are always bindings.
 */
interface SegmentConditionCompiler
{
    /**
     * Constrain the contacts query to the contacts matching the (complete) condition.
     */
    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void;
}
