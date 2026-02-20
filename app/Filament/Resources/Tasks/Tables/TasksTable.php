<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Task;
use Filament\Actions\CreateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
}
