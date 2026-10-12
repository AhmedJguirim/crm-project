<?php

namespace App\Filament\Resources\CompanyTypes\Tables;

use App\Filament\Resources\CompanyTypes\Actions\CompanyTypeDeletionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CompanyTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('companies_count')
                    ->label('Companies')
                    ->counts('companies')
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
                CompanyTypeDeletionActions::delete(),
                CompanyTypeDeletionActions::restore(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CompanyTypeDeletionActions::deleteBulk(),
                    CompanyTypeDeletionActions::restoreBulk(),
                ]),
            ])
            ->emptyStateHeading('No company types yet')
            ->emptyStateDescription('Create a type to group your companies, for example "Agency" or "Startup".')
            ->emptyStateIcon(Heroicon::OutlinedSquares2x2);
    }
}
