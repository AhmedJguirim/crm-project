<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\ActivityType;
use App\Models\Activity;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->toggleable(),

                TextColumn::make('tags')
                    ->badge()
                    ->color(fn ($record, $state): array => Color::hex($state['color'] ?? '#94a3b8'))
                    ->formatStateUsing(fn ($state): string => $state['name'])
                    ->separator(',')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(ViewAction::class)
            ->recordActions([
                Action::make('logActivity')
                    ->label('Log')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->modalHeading('Quick Log Activity')
                    ->schema([
                        Select::make('type')
                            ->options(ActivityType::class)
                            ->required(),

                        DateTimePicker::make('occurred_at')
                            ->label('Date & Time')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Textarea::make('notes')
                            ->rows(3),
                    ])
                    ->action(function (array $data, $record): void {
                        Activity::create([
                            ...$data,
                            'contact_id' => $record->getKey(),
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Activity logged')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Add your first contact or import from CSV.');
    }
}
