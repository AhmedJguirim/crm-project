<?php

namespace App\Services\Reports;

use App\Enums\DealAttentionReason;
use App\Enums\TaskStatus;
use App\Models\Deal;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The open deals of the current organization that need a push: past their expected close date, without a pending task,
 * without activity for INACTIVITY_DAYS days, or in the same stage for more than STAGE_DAYS days. The four conditions are
 * written once (the private helpers) and used both to filter (`constrain()`) and to flag each row
 * (`withReasonFlags()` + `reasonsFor()`).
 *
 * The stage rule reads the deal's latest stage change and has no `created_at` fallback when that row is missing or is
 * for another stage, like `Deal::stageEnteredAt()`: the reason is simply not raised.
 */
final class DealsNeedingAttention
{
    public const INACTIVITY_DAYS = 14;

    public const STAGE_DAYS = 30;

    /**
     * Restricts a deals query to open deals with at least one reason.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public static function constrain(Builder $query): Builder
    {
        [$activityCutoff, $stageCutoff] = self::cutoffs();

        return $query->open()->where(function (Builder $query) use ($activityCutoff, $stageCutoff): void {
            $query->where(fn (Builder $q) => self::pastCloseDate($q))
                ->orWhereDoesntHave('tasks', fn (Builder $t) => self::pendingTask($t))
                ->orWhere(fn (Builder $q) => $q->where('deals.created_at', '<', $activityCutoff)
                    ->whereDoesntHave('activities', fn (Builder $a) => self::recentActivity($a, $activityCutoff)))
                ->orWhereHas('latestStageChange', fn (Builder $s) => self::stuckStageChange($s, $stageCutoff));
        });
    }

    /**
     * Adds the boolean aliases has_pending_task, has_recent_activity and is_stuck_in_stage, so the reasons of each row
     * need no query.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public static function withReasonFlags(Builder $query): Builder
    {
        [$activityCutoff, $stageCutoff] = self::cutoffs();

        return $query->withExists([
            'tasks as has_pending_task' => fn (Builder $t) => self::pendingTask($t),
            'activities as has_recent_activity' => fn (Builder $a) => self::recentActivity($a, $activityCutoff),
            'latestStageChange as is_stuck_in_stage' => fn (Builder $s) => self::stuckStageChange($s, $stageCutoff),
        ]);
    }

    /**
     * The reasons of a deal loaded with `withReasonFlags()`, in enum case order.
     *
     * @return list<DealAttentionReason>
     */
    public static function reasonsFor(Deal $deal): array
    {
        [$activityCutoff] = self::cutoffs();

        return array_values(array_filter([
            $deal->isOverdue() ? DealAttentionReason::PastCloseDate : null,
            ! $deal->getAttribute('has_pending_task') ? DealAttentionReason::NoNextStep : null,
            ! $deal->getAttribute('has_recent_activity') && $deal->created_at->lt($activityCutoff) ? DealAttentionReason::NoRecentActivity : null,
            $deal->getAttribute('is_stuck_in_stage') ? DealAttentionReason::StuckInStage : null,
        ]));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} the activity cutoff and the stage cutoff
     */
    private static function cutoffs(): array
    {
        $now = CarbonImmutable::now();

        return [$now->subDays(self::INACTIVITY_DAYS), $now->subDays(self::STAGE_DAYS)];
    }

    /**
     * An expected close date before the organization's local today (a deal closing today is not late).
     *
     * @param  Builder<Deal>  $query
     */
    private static function pastCloseDate(Builder $query): void
    {
        $query->whereNotNull('deals.expected_close_date')
            ->where('deals.expected_close_date', '<', LocalTime::todayDate());
    }

    /**
     * A task that is still pending, whatever its due date (soft-deleted tasks are excluded by the model).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function pendingTask(Builder $query): void
    {
        $query->where('tasks.status', TaskStatus::Pending->value);
    }

    /**
     * An activity logged on the deal itself at or after the cutoff (future-dated ones count).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function recentActivity(Builder $query, CarbonImmutable $cutoff): void
    {
        $query->where('contact_activities.occurred_at', '>=', $cutoff);
    }

    /**
     * A latest stage change into the deal's current stage that happened before the cutoff.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function stuckStageChange(Builder $query, CarbonImmutable $cutoff): void
    {
        $query->whereColumn('deal_stage_changes.to_pipeline_stage_id', 'deals.pipeline_stage_id')
            ->where('deal_stage_changes.changed_at', '<', $cutoff);
    }
}
