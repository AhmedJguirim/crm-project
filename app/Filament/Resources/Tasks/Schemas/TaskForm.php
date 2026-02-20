<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Contact;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
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
