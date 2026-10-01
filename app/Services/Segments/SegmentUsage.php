<?php

namespace App\Services\Segments;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the segments whose conditions reference a record (custom field, tag, company or company type),
 * looking at both the published rules and the unsaved draft of every segment.
 */
class SegmentUsage
{
    /** @return Collection<int, Segment> */
    public static function segmentsUsing(Model $record): Collection
    {
        return self::query($record)?->get(['id', 'name']) ?? new Collection;
    }

    public static function isUsed(Model $record): bool
    {
        return self::segmentsUsing($record)->isNotEmpty();
    }

    /**
     * The option values of a select / multi-select custom field used by segment conditions, with the names of the
     * segments using each of them.
     *
     * @return array<string, array<int, string>>
     */
    public static function usedOptionValues(CustomField $field): array
    {
        $usages = [];

        foreach (self::query($field)?->get() ?? [] as $segment) {
            collect([$segment->publishedRules(), $segment->workingRules()])
                ->flatten(1)
                ->flatMap(fn (SegmentRuleData $rule): array => $rule->conditions)
                ->filter(fn (SegmentConditionData $condition): bool => $condition->type === SegmentConditionType::CustomField
                    && $condition->field === $field->key)
                ->flatMap(fn (SegmentConditionData $condition): array => [...$condition->values(), $condition->value('value')])
                ->filter(fn (mixed $value): bool => filled($value))
                ->each(function (mixed $value) use (&$usages, $segment): void {
                    $usages[(string) $value][] = $segment->name;
                });
        }

        return array_map(fn (array $segmentNames): array => array_values(array_unique($segmentNames)), $usages);
    }

    /** @return Builder<Segment>|null */
    private static function query(Model $record): ?Builder
    {
        $fragments = self::conditionFragments($record);

        if ($fragments === []) {
            return null;
        }

        return Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $record->getAttribute('organization_id'))
            ->where(function (Builder $query) use ($fragments): void {
                foreach ($fragments as $fragment) {
                    $pattern = json_encode([['conditions' => [$fragment]]]);

                    $query
                        ->orWhereRaw('rules @> CAST(? AS jsonb)', [$pattern])
                        ->orWhereRaw('draft_rules @> CAST(? AS jsonb)', [$pattern]);
                }
            })
            ->orderBy('name');
    }

    /** @param  Collection<int, Segment>  $segments */
    public static function describeSegments(Collection $segments): string
    {
        $names = $segments->pluck('name')->map(fn (string $name): string => "\"{$name}\"");

        return ($names->count() === 1 ? 'the segment ' : 'the segments ').$names->join(', ', ' and ');
    }

    /**
     * Partial conditions matching the conditions that reference the record. Ids are matched both as integers and
     * strings, as older conditions may have stored them either way.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function conditionFragments(Model $record): array
    {
        $byIds = fn (SegmentConditionType $type, SegmentOperator ...$operators): array => collect($operators ?: [null])
            ->crossJoin([$record->getKey(), (string) $record->getKey()])
            ->map(fn (array $pair): array => array_filter([
                'type' => $type->value,
                'operator' => $pair[0]?->value,
                'value' => ['values' => [$pair[1]]],
            ]))
            ->all();

        return match (true) {
            $record instanceof CustomField => [['type' => SegmentConditionType::CustomField->value, 'field' => $record->key]],
            $record instanceof Tag => $byIds(SegmentConditionType::Tags),
            $record instanceof Company => $byIds(SegmentConditionType::Company, SegmentOperator::BelongsToAnyOf, SegmentOperator::BelongsToNoneOf),
            $record instanceof CompanyType => $byIds(SegmentConditionType::Company, SegmentOperator::CompanyTypeIsAnyOf),
            default => [],
        };
    }
}
