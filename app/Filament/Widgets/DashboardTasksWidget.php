<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardTasksWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected static ?int $sort = -16;

    protected int|string|array $columnSpan = 2;

    protected function getStats(): array
    {
        $overdueCount = Task::query()->overdue()->count();
        $dueThisWeekCount = Task::query()
            ->dueThisWeek()
            ->where('status', TaskStatus::Pending)
            ->count();

        $allCaughtUp = $overdueCount === 0 && $dueThisWeekCount === 0;

        return [
            Stat::make('Overdue', (string) $overdueCount)
                ->description($allCaughtUp ? 'All caught up!' : 'Needs attention')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(TaskResource::getUrl('index').'?tableFilters[overdue][isActive]=1'),

            Stat::make('Due this week', (string) $dueThisWeekCount)
                ->description($allCaughtUp ? '0 overdue • 0 this week' : 'Upcoming tasks this week')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('warning')
                ->url(TaskResource::getUrl('index').'?tableFilters[this_week][isActive]=1'),
        ];
    }
}
