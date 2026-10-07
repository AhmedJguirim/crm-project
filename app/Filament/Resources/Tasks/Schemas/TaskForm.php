<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\MoneyLimit;
use Filament\Facades\Filament;
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

                Select::make('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', Filament::getTenant()?->id)
                    )
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

                Select::make('deal_id')
                    ->label('Deal')
                    ->relationship(
                        name: 'deal',
                        titleAttribute: 'title',
                        modifyQueryUsing: fn ($query, Get $get) => $query
                            ->where('organization_id', Filament::getTenant()?->id)
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
                            'title' => $data['title'],
                            'stage' => $data['stage'],
                            'value' => $data['value'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'status' => DealStatus::Open,
                            'created_by' => Auth::id(),
                        ])->getKey();
                    })
                    ->rules([
                        fn (Get $get) => Rule::exists(Deal::class, 'id')->where(
                            fn ($query) => $query
                                ->where('organization_id', Filament::getTenant()?->id)
                                ->where('contact_id', $get('contact_id'))
                        ),
                    ]),

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
