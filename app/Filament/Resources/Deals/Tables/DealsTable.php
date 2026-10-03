<?php

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Deal;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('No contact')
                    ->url(fn (Deal $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null),

                TextColumn::make('value')
                    ->money(fn (Deal $record): string => $record->currency)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('stage')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('expected_close_date')
                    ->label('Expected Close')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->options(DealStage::class)
                    ->multiple(),

                SelectFilter::make('status')
                    ->options(DealStatus::class),

                SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('closing_this_week')
                    ->label('Closing This Week')
                    ->query(fn (Builder $query): Builder => $query->closingThisWeek()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No deals yet')
            ->emptyStateDescription('Create your first deal to start tracking your pipeline.');
    }
}
