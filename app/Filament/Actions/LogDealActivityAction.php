<?php

namespace App\Filament\Actions;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Filament\Support\AbilityCheck;
use App\Models\Activity;
use App\Models\Deal;
use App\Services\Deals\DealStageMover;
use App\Support\MoneyLimit;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class LogDealActivityAction
{
    /**
     * Logs an activity on the action's record (a deal) for that deal's contact; hidden when the deal has no contact.
     */
    public static function make(string $name = 'logActivity'): Action
    {
        return Action::make($name)
            ->authorize(AbilityCheck::for('create', Activity::class))
            ->label('Log Activity')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (?Deal $record): bool => filled($record?->contact_id))
            ->modalHeading('Log Activity')
            ->fillForm(fn (Deal $record): array => [
                'deal' => $record->title,
                'contact_name' => $record->contact?->name,
                'deal_id' => $record->getKey(),
                'contact_id' => $record->contact_id,
                'occurred_at' => now(),
            ])
            ->schema(fn (?Deal $record): array => $record === null ? [] : self::schema($record))
            ->action(function (array $data, Deal $record, Component $livewire): void {
                Activity::create([
                    'contact_id' => $record->contact_id,
                    'user_id' => auth()->id(),
                    'type' => $data['type'],
                    'occurred_at' => $data['occurred_at'],
                    'duration_minutes' => $data['duration_minutes'] ?? null,
                    'subject' => $data['subject'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'outcome' => $data['outcome'] ?? null,
                    'deal_id' => $data['deal_id'],
                ]);

                Notification::make()
                    ->title('Activity logged')
                    ->success()
                    ->send();

                $livewire->dispatch('activityLogged');
            });
    }

    /**
     * @return array<int, TextInput|Grid|Textarea|Select>
     */
    protected static function schema(Deal $deal): array
    {
        return [
            TextInput::make('deal')
                ->label('Deal')
                ->disabled()
                ->dehydrated(false),

            TextInput::make('contact_name')
                ->label('Contact')
                ->disabled()
                ->dehydrated(false),

            Grid::make(2)->schema([
                Select::make('type')
                    ->options(ActivityType::class)
                    ->required()
                    ->live(),

                DateTimePicker::make('occurred_at')
                    ->label('Date & Time')
                    ->required()
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

            Select::make('deal_id')
                ->label('Deal')
                ->options(fn (): array => Deal::query()
                    ->where('organization_id', $deal->organization_id)
                    ->where('contact_id', $deal->contact_id)
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->createOptionForm([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Select::make('stage')
                        ->options(DealStage::class)
                        ->default(DealStage::Lead)
                        ->required(),
                    TextInput::make('value')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(MoneyLimit::MAX)
                        ->nullable(),
                    Textarea::make('deal_notes')
                        ->rows(3)
                        ->nullable(),
                ])
                ->createOptionUsing(function (array $data) use ($deal): int {
                    return Deal::create([
                        'organization_id' => $deal->organization_id,
                        'contact_id' => $deal->contact_id,
                        'title' => $data['title'],
                        ...DealStageMover::attributesFor($data['stage']),
                        'value' => $data['value'] ?? null,
                        'notes' => $data['deal_notes'] ?? null,
                        'created_by' => auth()->id(),
                    ])->getKey();
                }),
        ];
    }
}
