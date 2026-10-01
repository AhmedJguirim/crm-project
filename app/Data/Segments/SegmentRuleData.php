<?php

namespace App\Data\Segments;

use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A named group of conditions. A contact matches the rule when ALL of its conditions are met.
 */
class SegmentRuleData extends Data
{
    /** @param  array<int, SegmentConditionData>  $conditions */
    public function __construct(
        public string $id,
        public string $name,
        #[DataCollectionOf(SegmentConditionData::class)]
        public array $conditions = [],
    ) {}

    public static function make(string $name): self
    {
        return new self((string) Str::ulid(), $name);
    }

    public function findCondition(string $conditionId): ?SegmentConditionData
    {
        return collect($this->conditions)->firstWhere('id', $conditionId);
    }
}
