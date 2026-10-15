<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Filament\Support\NullsLastSort;
use App\Filament\Support\RecordKeyField;
use App\Models\Task;
use App\Services\Owners\OwnerOptions;
use App\Support\LocalTime;
use App\Support\NotificationText;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('owner.organizations'))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 50 ? $state : null),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('—')
                    ->url(fn (Task $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('owner_id')
                    ->label('Assigned to')
                    ->state(fn (Task $record): ?string => $record->ownerLabel())
                    ->placeholder('Unassigned'),

                TextColumn::make('due_at')
                    ->label('Due Date')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('Someday')
                    ->badge()
                    ->color(function (Task $record): string {
                        if ($record->isOverdue()) {
                            return 'danger';
                        }

                        return $record->status === TaskStatus::Done ? 'success' : 'gray';
                    })
                    ->sortable(query: NullsLastSort::column('due_at')),

                TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('priority')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('overdue')
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Task> $query */
                        return $query->overdue();
                    }),

                Filter::make('today')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereBetween('due_at', LocalTime::period('day'))
                        ->where('status', TaskStatus::Pending)),

                Filter::make('this_week')
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Task> $query */
                        return $query->dueThisWeek();
                    }),

                OptionSelectFilter::make('status')
                    ->options(TaskStatus::class)
                    ->default(TaskStatus::Pending->value),

                RecordKeySelectFilter::make('owner_id')
                    ->label('Assigned to')
                    ->options(fn (): array => OwnerOptions::forFilter(Task::class))
                    ->searchable(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('due_at');
            })
            ->recordActions([
                ActionGroup::make([
                    Action::make('snooze1Day')
                        ->authorize('update')
                        ->label('+1 day')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 1, 'Task snoozed for 1 day');
                        }),

                    Action::make('snooze3Days')
                        ->authorize('update')
                        ->label('+3 days')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 3, 'Task snoozed for 3 days');
                        }),

                    Action::make('snooze1Week')
                        ->authorize('update')
                        ->label('+1 week')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 7, 'Task snoozed for 1 week');
                        }),

                    Action::make('snoozeCustom')
                        ->authorize('update')
                        ->label('Custom')
                        ->schema([TaskForm::snoozeDueAtPicker()])
                        ->action(function (Task $record, array $data): void {
                            $record->update([
                                'due_at' => $data['due_at'],
                            ]);

                            Notification::make()
                                ->title('Task rescheduled successfully')
                                ->success()
                                ->send();
                        }),
                ])
                    ->label('Snooze')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Done),

                Action::make('markDone')
                    ->authorize('update')
                    ->label('Mark Done')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Done)
                    ->action(function (Task $record): void {
                        $record->update([
                            'status' => TaskStatus::Done,
                            'completed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Task marked as done')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make('delete')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Task')
                    ->modalDescription('Are you sure you want to delete this task? This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete task'),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignTasks')
                        ->authorize('updateAny')
                        ->authorizeIndividualRecords('update')
                        ->label('Assign to')
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->modalHeading('Assign tasks')
                        ->modalSubmitActionLabel('Assign')
                        ->schema([
                            RecordKeyField::select('owner_id')
                                ->label('Assigned to')
                                ->options(fn (): array => OwnerOptions::forForm(null))
                                ->in(fn (): array => array_keys(OwnerOptions::forForm(null)))
                                ->searchable()
                                ->placeholder('Unassigned'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $ownerId = filled($data['owner_id'] ?? null) ? (int) $data['owner_id'] : null;

                            $records->each(fn (Task $task) => $task->update(['owner_id' => $ownerId]));

                            $count = $records->count();
                            $noun = Str::plural('task', $count);

                            Notification::make()
                                ->title(NotificationText::plain($ownerId === null
                                    ? "Unassigned {$count} {$noun}"
                                    : "Assigned {$count} {$noun} to ".OwnerOptions::forForm(null)[$ownerId]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading('No tasks yet')
            ->emptyStateDescription('Create your first reminder or follow-up task.')
            ->emptyStateActions([
                CreateAction::make()->label('Create your first task'),
            ]);
    }

    private static function snoozeTask(Task $record, int $days, string $message): void
    {
        $baseDueDate = $record->due_at?->isFuture() ? $record->due_at->copy() : now();

        $record->update([
            'due_at' => $baseDueDate->addDays($days),
        ]);

        Notification::make()
            ->title(NotificationText::plain($message))
            ->success()
            ->send();
    }
}
