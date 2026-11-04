<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard doughnut of the task statuses: the tasks still open and on time, the open ones overdue (both as of
 * now) and the ones completed in the page's period. The CRM has no ticket system: the tasks are the open work. The
 * owner filter applies to the task owner.
 */
class TaskStatusWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Task status breakdown';

    protected ?string $emptyStateHeading = 'No tasks';

    public function getDescription(): string|Htmlable|null
    {
        return 'Open tasks now, split into on time and overdue, and tasks completed '.$this->filters()->period->phrase().'.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $filters = $this->filters();
        $tasks = fn (): Builder => Task::query()->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId));

        $open = $tasks()->where('status', TaskStatus::Pending->value)->count();
        $overdue = $tasks()->overdue()->count();
        $completed = $tasks()->completed()->whereBetween('completed_at', $filters->period->bounds())->count();

        return $this->categories(
            ['Open, on time' => $open - $overdue, 'Overdue' => $overdue, 'Completed' => $completed],
            'Tasks',
            sorted: false,
            colorsByLabel: ['Open, on time' => '#3b82f6', 'Overdue' => '#ef4444', 'Completed' => '#16a34a'],
        );
    }

    protected function getOptions(): array
    {
        return $this->doughnutOptions();
    }
}
