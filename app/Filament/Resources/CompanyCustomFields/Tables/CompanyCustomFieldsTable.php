<?php

namespace App\Filament\Resources\CompanyCustomFields\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompanyCustomFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('companyType.name')
                    ->label('Company Type')
                    ->searchable()
                    ->sortable(),

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
                SelectFilter::make('company_type_id')
                    ->label('Company Type')
                    ->relationship('companyType', 'name'),
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
            ->emptyStateHeading('No company custom fields defined yet')
            ->emptyStateDescription('Create your first custom field to extend company data.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }
}
