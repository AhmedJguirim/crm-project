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
use Illuminate\Database\Eloquent\Model;

/**
 * What each organization's segments reference (custom field keys, tag, company and company type IDs), loaded once
 * per request. For display only: enforcement goes through SegmentUsage, which always queries the database.
 *
 * It applies the same matching as {@see SegmentUsage}: the published rules and the draft of every segment are read,
 * and the IDs are matched as integers whether they were stored as integers or as strings.
 */
class SegmentUsageIndex
{
    /** @var array<int, array{custom_fields: array<string, list<string>>, tags: array<int, list<string>>, companies: array<int, list<string>>, company_types: array<int, list<string>>}> */
    private array $byOrganization = [];

    /**
     * @param  array<int, string>  $segmentNames
     */
    public static function describe(array $segmentNames): string
    {
        $names = collect($segmentNames)->map(fn (string $name): string => "\"{$name}\"");

        return ($names->count() === 1 ? 'the segment ' : 'the segments ').$names->join(', ', ' and ');
    }

    /** @return list<string> The names of the segments using the record, in alphabetical order. */
    public function segmentNamesUsing(Model $record): array
    {
        $organizationId = $record->getAttribute('organization_id');

        $kind = match (true) {
            $record instanceof CustomField => ['custom_fields', (string) $record->key],
            $record instanceof Tag => ['tags', (int) $record->getKey()],
            $record instanceof Company => ['companies', (int) $record->getKey()],
            $record instanceof CompanyType => ['company_types', (int) $record->getKey()],
            default => null,
        };

        if ($organizationId === null || $kind === null) {
            return [];
        }

        return $this->usageOf((int) $organizationId)[$kind[0]][$kind[1]] ?? [];
    }

    public function isUsed(Model $record): bool
    {
        return $this->segmentNamesUsing($record) !== [];
    }

    /** @return array{custom_fields: array<string, list<string>>, tags: array<int, list<string>>, companies: array<int, list<string>>, company_types: array<int, list<string>>} */
    private function usageOf(int $organizationId): array
    {
        return $this->byOrganization[$organizationId] ??= $this->load($organizationId);
    }

    /** @return array{custom_fields: array<string, list<string>>, tags: array<int, list<string>>, companies: array<int, list<string>>, company_types: array<int, list<string>>} */
    private function load(int $organizationId): array
    {
        $usage = ['custom_fields' => [], 'tags' => [], 'companies' => [], 'company_types' => []];

        Segment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'rules', 'draft_rules'])
            ->each(function (Segment $segment) use (&$usage): void {
                $rules = [...SegmentRuleData::collect($segment->rules ?? []), ...SegmentRuleData::collect($segment->draft_rules ?? [])];

                foreach ($rules as $rule) {
                    foreach ($rule->conditions as $condition) {
                        $this->record($usage, $segment->name, $condition);
                    }
                }
            });

        return $usage;
    }

    /**
     * @param  array{custom_fields: array<string, list<string>>, tags: array<int, list<string>>, companies: array<int, list<string>>, company_types: array<int, list<string>>}  $usage
     */
    private function record(array &$usage, string $segmentName, SegmentConditionData $condition): void
    {
        if ($condition->type === SegmentConditionType::CustomField && filled($condition->field)) {
            $this->add($usage['custom_fields'], $condition->field, $segmentName);

            return;
        }

        $ids = array_map('intval', $condition->values());

        $bucket = match (true) {
            $condition->type === SegmentConditionType::Tags => 'tags',
            $condition->type === SegmentConditionType::Company && in_array($condition->operator, [SegmentOperator::BelongsToAnyOf, SegmentOperator::BelongsToNoneOf], true) => 'companies',
            $condition->type === SegmentConditionType::Company && $condition->operator === SegmentOperator::CompanyTypeIsAnyOf => 'company_types',
            default => null,
        };

        if ($bucket === null) {
            return;
        }

        foreach ($ids as $id) {
            $this->add($usage[$bucket], $id, $segmentName);
        }
    }

    /**
     * @param  array<int|string, list<string>>  $bucket
     */
    private function add(array &$bucket, int|string $key, string $segmentName): void
    {
        if (! in_array($segmentName, $bucket[$key] ?? [], true)) {
            $bucket[$key][] = $segmentName;
        }
    }
}
