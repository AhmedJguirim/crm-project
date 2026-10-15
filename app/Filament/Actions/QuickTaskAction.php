<?php

namespace App\Filament\Actions;

use App\Enums\DealStage;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Support\AbilityCheck;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Services\Deals\DealCompany;
use App\Services\Deals\DealStageMover;
use App\Support\MoneyLimit;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class QuickTaskAction
{
    public static function makeGlobal(string $name = 'quickTask'): Action
    {
        return Action::make($name)
            ->label('Quick Task')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('Quick Task')
            ->modalSubmitActionLabel('Create Task')
            ->authorize(AbilityCheck::for('create', Task::class))
            ->schema(self::getBaseSchema())
            ->action(fn (array $data): Task => self::createTask($data));
    }

    public static function makeForContact(Contact $contact, string $name = 'quickTask'): Action
    {
        return Action::make($name)
            ->label('Quick Task')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('Quick Task')
            ->modalSubmitActionLabel('Create Task')
            ->authorize(AbilityCheck::for('create', Task::class))
            ->fillForm([
                ...self::defaultFormData(),
                'contact_id' => $contact->getKey(),
                'contact_name' => filled($contact->name) ? $contact->name : $contact->email,
            ])
            ->schema(self::getLockedContactSchema())
            ->action(fn (array $data): Task => self::createTask($data));
    }

    public static function makeForContactsTable(string $name = 'addTask'): Action
    {
        return Action::make($name)
            ->label('Add Task')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->modalHeading('Quick Task')
            ->modalSubmitActionLabel('Create Task')
            ->authorize(AbilityCheck::for('create', Task::class))
            ->fillForm(fn (Contact $record): array => [
                ...self::defaultFormData(),
                'contact_id' => $record->getKey(),
                'contact_name' => filled($record->name) ? $record->name : $record->email,
            ])
            ->schema(self::getLockedContactSchema())
            ->action(fn (array $data): Task => self::createTask($data));
    }

    /**
     * Creates a task for the action's record (a deal) and its live contact, with the deal pre-selected; hidden when the deal has no live contact.
     */
    public static function makeForDeal(string $name = 'addTask'): Action
    {
        return Action::make($name)
            ->label('Add Task')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->modalHeading('Quick Task')
            ->modalSubmitActionLabel('Create Task')
            ->authorize(AbilityCheck::for('create', Task::class))
            ->visible(fn (?Deal $record): bool => $record?->contact !== null)
            ->fillForm(fn (Deal $record): array => [
                ...self::defaultFormData(),
                'contact_id' => $record->contact->getKey(),
                'contact_name' => filled($record->contact->name) ? $record->contact->name : $record->contact->email,
                'deal_id' => $record->getKey(),
            ])
            ->schema(self::getLockedContactSchema())
            ->action(fn (array $data): Task => self::createTask($data));
    }

    /**
     * The values a quick task form starts with, because fillForm() replaces the field defaults.
     *
     * @return array{due_at: CarbonImmutable, type: TaskType}
     */
    private static function defaultFormData(): array
    {
        return [
            'due_at' => now()->addDays(3),
            'type' => TaskType::FollowUp,
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function getBaseSchema(): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255),

            DateTimePicker::make('due_at')
                ->label('Due Date')
                ->default(now()->addDays(3))
                ->native(false)
                ->nullable(),

            RecordKeyField::select('contact_id')
                ->label('Contact')
                ->options(fn (): array => Contact::query()
                    ->where('organization_id', Filament::getTenant()?->id)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->nullable()
                ->rules([
                    fn () => Rule::exists(Contact::class, 'id')->where(
                        fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                    ),
                ])
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('deal_id', null);
                }),

            self::dealSelect(),

            Select::make('type')
                ->options(TaskType::class)
                ->default(TaskType::FollowUp)
                ->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function getLockedContactSchema(): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255),

            DateTimePicker::make('due_at')
                ->label('Due Date')
                ->default(now()->addDays(3))
                ->native(false)
                ->nullable(),

            TextInput::make('contact_name')
                ->label('Contact')
                ->disabled()
                ->dehydrated(false),

            RecordKeyField::hidden('contact_id')
                ->required()
                ->rules([
                    fn () => Rule::exists(Contact::class, 'id')->where(
                        fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                    ),
                ]),

            self::dealSelect(),

            Select::make('type')
                ->options(TaskType::class)
                ->default(TaskType::FollowUp)
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function createTask(array $data): Task
    {
        $task = Task::create([
            'organization_id' => Filament::getTenant()?->id,
            'created_by' => Auth::id(),
            'owner_id' => Auth::id(),
            'title' => $data['title'],
            'due_at' => $data['due_at'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'deal_id' => $data['deal_id'] ?? null,
            'type' => $data['type'] ?? TaskType::FollowUp,
            'priority' => TaskPriority::Medium,
            'status' => TaskStatus::Pending,
        ]);

        Notification::make()
            ->title('Task created successfully')
            ->success()
            ->send();

        return $task;
    }

    private static function dealSelect(): Select
    {
        return RecordKeyField::select('deal_id')
            ->label('Deal')
            ->options(function (Get $get): array {
                return Deal::query()
                    ->where('organization_id', Filament::getTenant()?->id)
                    ->when(
                        filled($get('contact_id')),
                        fn ($query) => $query->where('contact_id', $get('contact_id')),
                        fn ($query) => $query->whereRaw('1 = 0'),
                    )
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->all();
            })
            ->searchable()
            ->preload()
            ->nullable()
            ->visible(fn (Get $get): bool => filled($get('contact_id')))
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
                Textarea::make('notes')
                    ->rows(3)
                    ->nullable(),
            ])
            ->createOptionUsing(function (array $data, Get $get): int {
                return Deal::create([
                    'organization_id' => Filament::getTenant()?->id,
                    'contact_id' => $get('contact_id') ?: null,
                    'company_id' => DealCompany::defaultIdFor($get('contact_id')),
                    'title' => $data['title'],
                    ...DealStageMover::attributesFor($data['stage']),
                    'value' => $data['value'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                    'owner_id' => Auth::id(),
                ])->getKey();
            })
            ->rules([
                fn (Get $get) => Rule::exists(Deal::class, 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', Filament::getTenant()?->id)
                        ->where('contact_id', $get('contact_id'))
                ),
            ]);
    }
}
