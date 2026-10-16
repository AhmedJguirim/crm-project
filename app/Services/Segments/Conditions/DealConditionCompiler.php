<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\DealStatus;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

class DealConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function __construct(private readonly string $timezone) {}

    /**
     * The `deal_stages` values are pipeline stage ids: a junk, stale or other-organization value is dropped (or matches no stage), so the condition then matches no deal.
     * `overdue_only` (strictly JSON `true`) keeps the open deals whose expected close date is before today in the organization's timezone, like `Deal::isOverdue()`.
     */
    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $statuses = $condition->values('deal_statuses');
        $stages = $condition->values('deal_stages');
        $stageIds = $this->stageIds($stages);
        $minValue = $condition->value('min_value');

        $matchingDeals = fn (Builder $deals): Builder => $deals
            ->when($statuses !== [], fn (Builder $query): Builder => $query->whereIn('status', $statuses))
            ->when($stages !== [], fn (Builder $query): Builder => $query->whereIn('pipeline_stage_id', $stageIds))
            ->when(is_numeric($minValue), fn (Builder $query): Builder => $query->where('value', '>=', $minValue))
            ->when($condition->value('overdue_only') === true, fn (Builder $query): Builder => $query
                ->where('status', DealStatus::Open)
                ->whereNotNull('expected_close_date')
                ->where('expected_close_date', '<', LocalTime::todayDate($this->timezone)));

        match ($condition->operator) {
            SegmentOperator::HasDeal => $query->whereHas('deals', $matchingDeals),
            SegmentOperator::HasNoDeal => $query->whereDoesntHave('deals', $matchingDeals),
            default => throw $this->unsupported($condition),
        };
    }

    /**
     * @param  array<int, mixed>  $stages
     * @return array<int, int>
     */
    private function stageIds(array $stages): array
    {
        $ids = [];

        foreach ($stages as $stage) {
            $id = is_int($stage) || is_string($stage)
                ? filter_var($stage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
