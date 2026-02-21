<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->sortable(),

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
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Filter::make('today')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('due_at', now()->toDateString())
                        ->where('status', TaskStatus::Pending)),

                Filter::make('this_week')
                    ->query(fn (Builder $query): Builder => $query
                        ->dueThisWeek()),

                SelectFilter::make('status')
                    ->options(TaskStatus::class)
                    ->default(TaskStatus::Pending->value),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('due_at');
            })
            ->recordActions([
                ActionGroup::make([
                    Action::make('snooze1Day')
                        ->label('+1 day')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 1, 'Task snoozed for 1 day');
                        }),

                    Action::make('snooze3Days')
                        ->label('+3 days')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 3, 'Task snoozed for 3 days');
                        }),

                    Action::make('snooze1Week')
                        ->label('+1 week')
                        ->action(function (Task $record): void {
                            self::snoozeTask($record, 7, 'Task snoozed for 1 week');
                        }),

                    Action::make('snoozeCustom')
                        ->label('Custom')
                        ->schema([
                            DateTimePicker::make('due_at')
                                ->label('New Due Date')
                                ->required()
                                ->native(false)
                                ->minDate(now()),
                        ])
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
                    DeleteBulkAction::make(),
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
            ->title($message)
            ->success()
            ->send();
    }
}
