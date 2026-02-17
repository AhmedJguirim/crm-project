<?php

namespace App\Filament\Resources\CustomFields\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(fn ($record) => strlen($record->name) > 50 ? $record->name : null),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'select', 'multiselect' => 'info',
                        'email', 'url' => 'success',
                        'number', 'date' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('unique')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('order')
            ->defaultSort('order', 'asc')
            ->emptyStateHeading('No custom fields defined yet')
            ->emptyStateDescription('Create your first custom field to extend contact data.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }
}
