<?php

namespace App\Services\Reports;

use App\Enums\ActivityType;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The team leaderboard: per member of the current organization, what they logged and closed in a report period, with a
 * total row. Three grouped aggregate queries (activities, tasks, deals) feed every row; records without an owner or
 * user are not counted anywhere. The deal figures follow the same rules as the dashboard KPIs (DashboardKpis).
 */
final class TeamActivity
{
    /**
     * @return array<string, array{member: string, is_total: bool, activities: int, activities_breakdown: string, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int, win_rate: string}>
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): array
    {
        $figures = self::figures($period, $ownerId);
        $members = self::members();

        $names = [];
        $alwaysShown = [];

        foreach ($members as $id => $member) {
            $names[$id] = $member['name'];

            if ($member['role'] !== OrganizationRole::Viewer) {
                $alwaysShown[$id] = true;
            }
        }

        $unknownIds = array_values(array_filter(
            array_keys($figures),
            fn (int $id): bool => ! array_key_exists($id, $members) && self::hasFigure($figures[$id]),
        ));

        if ($unknownIds !== []) {
            foreach (User::query()->whereIn('id', $unknownIds)->pluck('name', 'id') as $id => $name) {
                $names[$id] = "{$name} (no longer a member)";
            }
        }

        $rows = [];

        foreach ($names as $id => $name) {
            if ($ownerId !== null && $id !== $ownerId) {
                continue;
            }

            $row = $figures[$id] ?? self::emptyFigures();

            if (! isset($alwaysShown[$id]) && ! self::hasFigure($row)) {
                continue;
            }

            $rows[$id] = ['member' => $name] + $row;
        }

        uasort($rows, fn (array $a, array $b): int => $b['won_value'] <=> $a['won_value']
            ?: $b['deals_won'] <=> $a['deals_won']
            ?: strcmp($a['member'], $b['member']));

        $result = [];

        foreach ($rows as $id => $row) {
            $result["user-{$id}"] = self::present($row['member'], false, $row);
        }

        $result['total'] = self::present('Total', true, self::sum($rows));

        return $result;
    }

    /**
     * @return array<int, array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}>
     */
    private static function figures(ReportPeriod $period, ?int $ownerId): array
    {
        [$from, $to] = $period->bounds();
        $figures = [];

        $activities = Activity::query()
            ->whereNotNull('user_id')
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('user_id', $ownerId))
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('user_id, type, COUNT(*) AS activities_count')
            ->groupBy('user_id', 'type')
            ->toBase()
            ->get();

        foreach ($activities as $row) {
            $type = ActivityType::tryFrom((string) $row->type);

            if ($type === null) {
                continue;
            }

            $figures[(int) $row->user_id] ??= self::emptyFigures();
            $figures[(int) $row->user_id]['types'][$type->value] = (int) $row->activities_count;
        }

        $tasks = Task::query()
            ->whereNotNull('owner_id')
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->selectRaw(
                'owner_id, '.
                'COUNT(CASE WHEN status = ? AND completed_at BETWEEN ? AND ? THEN 1 END) AS completed_count, '.
                'COUNT(CASE WHEN status = ? AND due_at < ? THEN 1 END) AS overdue_count',
                [TaskStatus::Done->value, $from, $to, TaskStatus::Pending->value, now()],
            )
            ->groupBy('owner_id')
            ->toBase()
            ->get();

        foreach ($tasks as $row) {
            $figures[(int) $row->owner_id] ??= self::emptyFigures();
            $figures[(int) $row->owner_id]['tasks_completed'] = (int) $row->completed_count;
            $figures[(int) $row->owner_id]['tasks_overdue'] = (int) $row->overdue_count;
        }

        $deals = Deal::query()
            ->whereNotNull('owner_id')
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->selectRaw(
                'owner_id, '.
                'COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS created_count, '.
                'COUNT(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN 1 END) AS won_count, '.
                'COALESCE(SUM(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN value END), 0) AS won_value, '.
                'COUNT(CASE WHEN status = ? AND lost_at BETWEEN ? AND ? THEN 1 END) AS lost_count',
                [
                    $from, $to,
                    DealStatus::Won->value, $from, $to,
                    DealStatus::Won->value, $from, $to,
                    DealStatus::Lost->value, $from, $to,
                ],
            )
            ->groupBy('owner_id')
            ->toBase()
            ->get();

        foreach ($deals as $row) {
            $figures[(int) $row->owner_id] ??= self::emptyFigures();
            $figures[(int) $row->owner_id]['deals_created'] = (int) $row->created_count;
            $figures[(int) $row->owner_id]['deals_won'] = (int) $row->won_count;
            $figures[(int) $row->owner_id]['won_value'] = (float) $row->won_value;
            $figures[(int) $row->owner_id]['deals_lost'] = (int) $row->lost_count;
        }

        return $figures;
    }

    /**
     * @return array<int, array{name: string, role: ?OrganizationRole}>
     */
    private static function members(): array
    {
        $members = [];

        foreach (CurrentOrganization::find()?->members()->get() ?? [] as $member) {
            $members[$member->id] = [
                'name' => $member->name,
                'role' => OrganizationRole::tryFrom((string) $member->pivot->getAttribute('role')),
            ];
        }

        return $members;
    }

    /**
     * @return array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}
     */
    private static function emptyFigures(): array
    {
        return [
            'types' => [],
            'tasks_completed' => 0,
            'tasks_overdue' => 0,
            'deals_created' => 0,
            'deals_won' => 0,
            'won_value' => 0.0,
            'deals_lost' => 0,
        ];
    }

    /**
     * @param  array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}  $figures
     */
    private static function hasFigure(array $figures): bool
    {
        return array_sum($figures['types']) > 0
            || $figures['tasks_completed'] > 0
            || $figures['tasks_overdue'] > 0
            || $figures['deals_created'] > 0
            || $figures['deals_won'] > 0
            || $figures['deals_lost'] > 0
            || $figures['won_value'] > 0;
    }

    /**
     * @param  array<int, array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}>  $rows
     * @return array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}
     */
    private static function sum(array $rows): array
    {
        $total = self::emptyFigures();

        foreach ($rows as $row) {
            foreach ($row['types'] as $type => $count) {
                $total['types'][$type] = ($total['types'][$type] ?? 0) + $count;
            }

            foreach (['tasks_completed', 'tasks_overdue', 'deals_created', 'deals_won', 'deals_lost'] as $key) {
                $total[$key] += $row[$key];
            }

            $total['won_value'] += $row['won_value'];
        }

        return $total;
    }

    /**
     * @param  array{types: array<string, int>, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int}  $figures
     * @return array{member: string, is_total: bool, activities: int, activities_breakdown: string, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int, win_rate: string}
     */
    private static function present(string $member, bool $isTotal, array $figures): array
    {
        $breakdown = [];

        foreach (ActivityType::cases() as $type) {
            $count = $figures['types'][$type->value] ?? 0;

            if ($count > 0) {
                $breakdown[] = "{$type->getLabel()} {$count}";
            }
        }

        $closed = $figures['deals_won'] + $figures['deals_lost'];

        return [
            'member' => $member,
            'is_total' => $isTotal,
            'activities' => array_sum($figures['types']),
            'activities_breakdown' => implode(' · ', $breakdown),
            'tasks_completed' => $figures['tasks_completed'],
            'tasks_overdue' => $figures['tasks_overdue'],
            'deals_created' => $figures['deals_created'],
            'deals_won' => $figures['deals_won'],
            'won_value' => $figures['won_value'],
            'deals_lost' => $figures['deals_lost'],
            'win_rate' => $closed === 0
                ? '—'
                : (int) round($figures['deals_won'] * 100 / $closed)."% ({$figures['deals_won']} of {$closed})",
        ];
    }
}
