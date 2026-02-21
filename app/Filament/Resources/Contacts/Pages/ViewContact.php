<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Models\Activity;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ViewContact extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ContactResource::class;

    protected string $view = 'filament.resources.contacts.pages.view-contact';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ContactDetailsWidget::class,
            ContactActivityFeed::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            QuickTaskAction::makeForContact($this->getRecord()),

            Action::make('logActivity')
                ->label('Log Activity')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('primary')
                ->modalHeading('Log Activity')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('type')
                            ->options(ActivityType::class)
                            ->required()
                            ->live(),

                        DateTimePicker::make('occurred_at')
                            ->label('Date & Time')
                            ->required()
                            ->default(now())
                            ->native(false),
                    ]),

                    TextInput::make('duration_minutes')
                        ->label('Duration (minutes)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->suffix('min')
                        ->visible(function (Get $get): bool {
                            $type = $get('type');

                            if ($type instanceof ActivityType) {
                                return $type->hasDuration();
                            }

                            return ActivityType::tryFrom($type ?? '')?->hasDuration() ?? false;
                        }),

                    TextInput::make('subject')
                        ->maxLength(255),

                    Textarea::make('notes')
                        ->rows(3),

                    Select::make('outcome')
                        ->options(ActivityOutcome::class),

                    DateTimePicker::make('follow_up_at')
                        ->label('Follow-up Date')
                        ->default(now()->addWeek())
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('create_follow_up')),
                ])
                ->action(function (array $data): void {
                    $activityData = collect($data)->except(['create_follow_up'])->toArray();

                    $activityData['contact_id'] = $this->getRecord()->getKey();
                    $activityData['user_id'] = auth()->id();

                    Activity::create($activityData);

                    Notification::make()
                        ->title('Activity logged')
                        ->success()
                        ->send();

                    $this->dispatch('activityLogged');
                }),
            EditAction::make()
                ->record($this->getRecord()),
        ];
    }
}
