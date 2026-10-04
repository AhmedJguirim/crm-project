<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Support\SegmentUsageGuard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['companyType', 'address']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('companyType.name')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('contacts_count')
                    ->label('Contacts')
                    ->counts('contacts')
                    ->sortable(),

                TextColumn::make('address.city')
                    ->label('City')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('address.country')
                    ->label('Country')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('company_type_id')
                    ->label('Type')
                    ->relationship('companyType', 'name')
                    ->preload(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                RestoreAction::make(),
                SegmentUsageGuard::protectDelete(DeleteAction::make()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SegmentUsageGuard::protectBulkDelete(DeleteBulkAction::make()->authorizeIndividualRecords()),
                    RestoreBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading('No companies yet')
            ->emptyStateDescription('Create a company to track which companies your contacts work for.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2);
    }
}
