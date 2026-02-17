<?php

namespace App\Filament\Resources\Contacts\Tables;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
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
                    ->color(fn ($record, $state) => $state['color'])
                    ->formatStateUsing(fn ($state) => $state['name'])
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
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Add your first contact or import from CSV.');
    }
}
