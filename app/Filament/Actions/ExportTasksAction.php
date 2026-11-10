<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportTasksJob;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The two ways to export tasks from the tasks list: everything the table shows (tab, search, filters and sort
 * applied, across all pages), or the selected rows. Both queue an `ExportTasksJob`.
 */
class ExportTasksAction
{
    use QueuesExports;

    public static function forList(): Action
    {
        return Action::make('exportTasks')
            ->authorize('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export tasks')
            ->modalDescription('Exports the tasks the list shows now, across all pages.')
            ->schema([self::exportFormatField()])
            ->action(function (array $data, HasTable $livewire): void {
                $ids = $livewire->getFilteredSortedTableQuery()
                    ->pluck('tasks.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), 'tasks', ExportTasksJob::class);
            });
    }

    public static function forSelection(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export selected tasks')
            ->schema([self::exportFormatField()])
            ->action(function (Collection $records, array $data): void {
                /** @var EloquentCollection<int, Task> $records */
                self::queueExport($records->modelKeys(), self::exportFormatOf($data), 'tasks', ExportTasksJob::class);
            })
            ->deselectRecordsAfterCompletion();
    }
}
