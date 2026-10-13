<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Enums\CompanyIndustry;
use App\Filament\Actions\ExportCompaniesAction;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Filament\Support\NullsLastSort;
use App\Filament\Support\SegmentUsageGuard;
use App\Models\Company;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['companyTypeWithTrashed', 'address'])->withCount('contacts'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('companyTypeWithTrashed.name')
                    ->label('Type')
                    ->formatStateUsing(fn (Company $record): ?string => $record->companyTypeLabel())
                    ->badge()
                    ->placeholder('—')
                    ->sortable(query: NullsLastSort::relationship('companyTypeWithTrashed', 'name')),

                TextColumn::make('industry')
                    ->toggleable()
                    ->placeholder('—')
                    ->sortable(query: NullsLastSort::column('industry')),

                TextColumn::make('domain')
                    ->label('Website')
                    ->url(fn (Company $record): ?string => $record->websiteUrl(), shouldOpenInNewTab: true)
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('employees')
                    ->numeric()
                    ->sortable(query: NullsLastSort::column('employees'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('annual_revenue')
                    ->label('Annual revenue')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(query: NullsLastSort::column('annual_revenue'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('contacts_count')
                    ->label('Contacts')
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
                RecordKeySelectFilter::make('company_type_id')
                    ->label('Type')
                    ->relationship('companyType', 'name')
                    ->preload(),

                OptionSelectFilter::make('industry')
                    ->options(CompanyIndustry::class)
                    ->multiple(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                RestoreAction::make(),
                SegmentUsageGuard::protectDelete(DeleteAction::make()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportCompaniesAction::forSelection(),
                    SegmentUsageGuard::protectBulkDelete(DeleteBulkAction::make()->authorizeIndividualRecords()),
                    RestoreBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading('No companies yet')
            ->emptyStateDescription('Create a company to track which companies your contacts work for.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2);
    }
}
