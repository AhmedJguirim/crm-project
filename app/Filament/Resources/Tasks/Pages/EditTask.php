<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('snooze1Day')
                    ->authorize('update')
                    ->label('+1 day')
                    ->action(function (): void {
                        $this->snoozeTask(1, 'Task snoozed for 1 day');
                    }),

                Action::make('snooze3Days')
                    ->authorize('update')
                    ->label('+3 days')
                    ->action(function (): void {
                        $this->snoozeTask(3, 'Task snoozed for 3 days');
                    }),

                Action::make('snooze1Week')
                    ->authorize('update')
                    ->label('+1 week')
                    ->action(function (): void {
                        $this->snoozeTask(7, 'Task snoozed for 1 week');
                    }),

                Action::make('snoozeCustom')
                    ->authorize('update')
                    ->label('Custom')
                    ->schema([
                        DateTimePicker::make('due_at')
                            ->label('New Due Date')
                            ->required()
                            ->native(false)
                            ->minDate(now()),
                    ])
                    ->action(function (array $data): void {
                        $this->getRecord()->update([
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
                ->visible(fn (): bool => $this->getRecord()->status !== TaskStatus::Done),

            Action::make('markDone')
                ->authorize('update')
                ->label('Mark Done')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status !== TaskStatus::Done)
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => TaskStatus::Done,
                        'completed_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Task marked as done')
                        ->success()
                        ->send();
                }),

            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Task')
                ->modalDescription('Are you sure you want to delete this task? This action cannot be undone.')
                ->modalSubmitActionLabel('Yes, delete task'),
        ];
    }

    private function snoozeTask(int $days, string $message): void
    {
        $record = $this->getRecord();
        $baseDueDate = $record->due_at?->isFuture() ? $record->due_at->copy() : now();

        $record->update([
            'due_at' => $baseDueDate->addDays($days),
        ]);

        Notification::make()
            ->title($message)
            ->success()
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Task updated successfully';
    }
}
