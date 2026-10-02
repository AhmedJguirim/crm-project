<?php

namespace App\Services\Segments;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for live match counts shown while editing segments. The key is the exact conditions counted,
 * so editing a rule always produces a fresh count, while re-renders with unchanged rules reuse the last one.
 *
 * Counts may lag changes to the contacts by up to {@see self::TTL_SECONDS} seconds; segment membership is not
 * computed from them.
 */
class SegmentCountCache
{
    public const TTL_SECONDS = 60;

    /** @var array<string, int> */
    private array $memo = [];

    public function __construct(private readonly SegmentQueryBuilder $builder) {}

    /** @param  iterable<SegmentRuleData>  $rules */
    public function count(iterable $rules): int
    {
        $rules = collect($rules);

        return $this->remember(
            'rules',
            $rules->map(fn (SegmentRuleData $rule): array => $this->normalize($rule->conditions))->all(),
            fn (): int => $this->builder->count($rules),
        );
    }

    /** @param  array<int, SegmentConditionData>  $conditions */
    public function countConditions(array $conditions): int
    {
        return $this->remember(
            'conditions',
            $this->normalize($conditions),
            fn (): int => $this->builder->countConditions($conditions),
        );
    }

    /**
     * @param  array<int|string, mixed>  $normalized
     * @param  Closure(): int  $count
     */
    private function remember(string $kind, array $normalized, Closure $count): int
    {
        $key = "segment-count:{$this->builder->catalog()->organizationId}:".md5(json_encode([$kind, $normalized]));

        return $this->memo[$key] ??= Cache::remember($key, self::TTL_SECONDS, $count);
    }

    /**
     * The conditions without their IDs, so identical conditions share a key whatever rule they belong to.
     *
     * @param  array<int, SegmentConditionData>  $conditions
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $conditions): array
    {
        return array_map(function (SegmentConditionData $condition): array {
            $value = $condition->value;
            ksort($value);

            return [
                'type' => $condition->type->value,
                'field' => $condition->field,
                'operator' => $condition->operator->value,
                'value' => $value,
            ];
        }, array_values($conditions));
    }
}
