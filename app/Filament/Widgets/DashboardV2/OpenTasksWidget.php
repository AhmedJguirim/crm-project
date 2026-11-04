<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Support\LocalTime;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard summary of the open tasks (status pending) right now: how many there are, how many are overdue, due this
 * week and high priority. The CRM has no ticket system: the tasks are the open work. It is a snapshot (the period
 * filter does not apply); the owner filter applies to the task owner.
 */
class OpenTasksWidget extends DashboardStatsWidget
{
    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 2;

    protected function getHeading(): ?string
    {
        return 'Open task summary';
    }

    protected function getDescription(): ?string
    {
        return 'Tasks that are not done yet, as of now.';
    }

    protected function getStats(): array
    {
        $ownerId = $this->filters()->ownerId;
        $open = fn (): Builder => Task::query()
            ->where('status', TaskStatus::Pending->value)
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));

        $overdue = $open()->overdue()->count();
        $dueThisWeek = $open()->whereBetween('due_at', LocalTime::period('week'))->count();
        $highPriority = $open()->where('priority', TaskPriority::High->value)->count();

        return [
            Stat::make('Open Tasks', (string) $open()->count())->url(TaskResource::getUrl('index')),
            Stat::make('Overdue', (string) $overdue)->color($overdue > 0 ? 'danger' : 'gray'),
            Stat::make('Due This Week', (string) $dueThisWeek),
            Stat::make('High Priority', (string) $highPriority)->color($highPriority > 0 ? 'warning' : 'gray'),
        ];
    }
}
