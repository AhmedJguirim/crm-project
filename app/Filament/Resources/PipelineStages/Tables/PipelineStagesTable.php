<?php

namespace App\Filament\Resources\PipelineStages\Tables;

use App\Filament\Resources\PipelineStages\Actions\PipelineStageDeletionAction;
use App\Models\PipelineStage;
use Filament\Actions\Action;
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
                    ->label('Win probability')
                    ->headerTooltip('How likely a deal in this stage is to be won.')
                    ->formatStateUsing(fn (int $state): string => "{$state}%"),

                TextColumn::make('deals_count')
                    ->label('Deals'),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action->button()->label($isReordering ? 'Done reordering' : 'Reorder stages'))
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                PipelineStageDeletionAction::make(),
            ]);
    }
}
