<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Filament\Resources\Tags\Actions\TagDeletionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                ColorColumn::make('color')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                TagDeletionActions::delete(),
                TagDeletionActions::restore(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    TagDeletionActions::deleteBulk(),
                    TagDeletionActions::restoreBulk(),
                ]),
            ])
            ->emptyStateHeading('No tags yet')
            ->emptyStateDescription('Create your first tag to start organizing contacts.')
            ->emptyStateIcon('heroicon-o-tag');
    }
}
