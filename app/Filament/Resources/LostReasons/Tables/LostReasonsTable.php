<?php

namespace App\Filament\Resources\LostReasons\Tables;

use App\Filament\Resources\LostReasons\Actions\LostReasonDeletionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class LostReasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                LostReasonDeletionActions::delete(),
                LostReasonDeletionActions::restore(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    LostReasonDeletionActions::deleteBulk(),
                    LostReasonDeletionActions::restoreBulk(),
                ]),
            ])
            ->emptyStateHeading('No lost reasons yet')
            ->emptyStateDescription('Add the reasons your team loses deals for, for example "Price too high" or "Went with a competitor". Picking a reason will stay optional.')
            ->emptyStateIcon(Heroicon::OutlinedHandThumbDown);
    }
}
