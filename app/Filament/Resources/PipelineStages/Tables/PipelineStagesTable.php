<?php

namespace App\Filament\Resources\PipelineStages\Tables;

use App\Models\PipelineStage;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PipelineStagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('deals'))
            ->columns([
                TextColumn::make('name')
                    ->label('Stage')
                    ->badge()
                    ->color(fn (PipelineStage $record): string => $record->color),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),

                TextColumn::make('probability')
                    ->label('Probability')
                    ->formatStateUsing(fn (int $state): string => "{$state}%"),

                TextColumn::make('deals_count')
                    ->label('Deals'),
            ])
            ->defaultSort('position')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
