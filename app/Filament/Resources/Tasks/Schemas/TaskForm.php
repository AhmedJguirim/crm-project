<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Support\RecordKeyField;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Services\Deals\DealCompany;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Services\Owners\OwnerOptions;
use App\Support\LocalTime;
use App\Support\MoneyLimit;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaskForm
{
    /**
     * The picker of both "Custom" snooze actions. Its minimum is the start of today in the organization timezone, not
     * the current moment: the picker clears any date before its minimum on each keystroke, so a minimum of "now" would
     * wipe the hour while the user types it. Past days stay disabled in the calendar, and the server refuses a time
     * earlier than now (organization timezone) with its own message.
     */
    public static function snoozeDueAtPicker(): DateTimePicker
    {
        return DateTimePicker::make('due_at')
            ->label('New Due Date')
            ->required()
            ->native(false)
            ->minDate(fn (): string => LocalTime::now()->startOfDay()->format('Y-m-d H:i:s'))
            ->afterOrEqual(fn (): string => LocalTime::now()->format('Y-m-d H:i:s'))
            ->validationMessages(['after_or_equal' => "The new due date can't be in the past."]);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                DateTimePicker::make('due_at')
                    ->label('Due Date & Time')
                    ->default(now()->addDays(3))
                    ->native(false)
                    ->nullable(),

                RecordKeyField::select('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->rules([
                        fn () => Rule::exists(Contact::class, 'id')->where(
                            fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                        ),
                    ])
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('deal_id', null);
                    }),

                RecordKeyField::select('deal_id')
                    ->label('Deal')
                    ->relationship(
                        name: 'deal',
                        titleAttribute: 'title',
                        modifyQueryUsing: fn ($query, Get $get) => $query
                            ->where('organization_id', CurrentOrganization::find()?->id)
                            ->when(
                                filled($get('contact_id')),
                                fn ($builder) => $builder->where('contact_id', $get('contact_id')),
                                fn ($builder) => $builder->whereRaw('1 = 0'),
                            )
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn (Get $get): bool => filled($get('contact_id')))
                    ->createOptionForm([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        DealForm::stageSelect(),
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
                            'organization_id' => CurrentOrganization::find()?->id,
                            'contact_id' => $get('contact_id') ?: null,
                            'company_id' => DealCompany::defaultIdFor($get('contact_id')),
                            'title' => $data['title'],
                            ...DealStageMover::attributesFor(DefaultPipeline::stageById(CurrentOrganization::get()->id, $data['pipeline_stage_id'])),
                            'value' => $data['value'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'created_by' => Auth::id(),
                            'owner_id' => Auth::id(),
                        ])->getKey();
                    })
                    ->rules([
                        fn (Get $get) => Rule::exists(Deal::class, 'id')->where(
                            fn ($query) => $query
                                ->where('organization_id', CurrentOrganization::find()?->id)
                                ->where('contact_id', $get('contact_id'))
                        ),
                    ]),

                RecordKeyField::select('owner_id')
                    ->label('Assigned to')
                    ->options(fn (?Task $record): array => OwnerOptions::forForm($record))
                    ->in(fn (?Task $record): array => array_keys(OwnerOptions::forForm($record)))
                    ->default(fn (): ?int => Auth::id())
                    ->searchable()
                    ->nullable()
                    ->placeholder('Unassigned'),

                Select::make('type')
                    ->options(TaskType::class)
                    ->default(TaskType::FollowUp)
                    ->required(),

                Select::make('priority')
                    ->options(TaskPriority::class)
                    ->default(TaskPriority::Medium)
                    ->required(),

                Textarea::make('notes')
                    ->rows(4)
                    ->columnSpanFull(),

                Select::make('status')
                    ->options(TaskStatus::class)
                    ->default(TaskStatus::Pending)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }
}
