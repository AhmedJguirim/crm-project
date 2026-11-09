<?php

namespace App\Filament\Actions;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Filament\Support\AbilityCheck;
use App\Filament\Support\RecordKeyField;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Deal;
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

final class LogCompanyActivityAction
{
    /**
     * Logs an activity on the action's record (a company), optionally also linked to one of the company's deals.
     */
    public static function make(string $name = 'logActivity'): Action
    {
        return Action::make($name)
            ->authorize(AbilityCheck::for('create', Activity::class))
            ->label('Log Activity')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (?Company $record): bool => $record !== null && ! $record->trashed())
            ->modalHeading('Log Activity')
            ->fillForm(fn (): array => ['occurred_at' => now()])
            ->schema(fn (?Company $record): array => $record === null ? [] : self::schema($record))
            ->action(function (array $data, Company $record, Component $livewire): void {
                Activity::create([
                    'company_id' => $record->getKey(),
                    'contact_id' => null,
                    'deal_id' => $data['deal_id'] ?? null,
                    'user_id' => auth()->id(),
                    'type' => $data['type'],
                    'occurred_at' => $data['occurred_at'],
                    'duration_minutes' => $data['duration_minutes'] ?? null,
                    'subject' => $data['subject'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'outcome' => $data['outcome'] ?? null,
                ]);

                Notification::make()
                    ->title('Activity logged')
                    ->success()
                    ->send();

                $livewire->dispatch('activityLogged');
            });
    }

    /**
     * @return array<int, Grid|TextInput|Textarea|Select>
     */
    protected static function schema(Company $company): array
    {
        return [
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

            RecordKeyField::select('deal_id')
                ->label('Deal')
                ->options(fn (): array => Deal::query()
                    ->where('organization_id', $company->organization_id)
                    ->where('company_id', $company->getKey())
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->nullable(),
        ];
    }
}
