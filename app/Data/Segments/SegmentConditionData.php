<?php

namespace App\Data\Segments;

use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class SegmentConditionData extends Data
{
    /**
     * @param  array<string, mixed>  $value  Keys used depend on the operator's value input: `value`, `value_to`, `values`,
     *                                       `days`, `month`, `day`, `activity_types`, `outcome`, `deal_statuses`, `deal_stages`, `min_value`.
     */
    public function __construct(
        public string $id,
        public SegmentConditionType $type,
        public ?string $field,
        public SegmentOperator $operator,
        public array $value = [],
    ) {}

    /** @param  array<string, mixed>  $value */
    public static function make(SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): self
    {
        return new self((string) Str::ulid(), $type, $field, $operator, $value);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->value[$key] ?? $default;
    }

    /** @return array<int, mixed> */
    public function values(string $key = 'values'): array
    {
        return array_values(array_filter(
            (array) ($this->value[$key] ?? []),
            fn (mixed $item): bool => filled($item),
        ));
    }
}
