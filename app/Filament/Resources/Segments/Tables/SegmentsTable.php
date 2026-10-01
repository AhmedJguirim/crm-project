<?php

namespace App\Filament\Resources\Segments\Tables;

use App\Enums\SegmentStatus;
use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Segment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class SegmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->state(fn (Segment $record): SegmentStatus => $record->status())
                    ->badge(),

                TextColumn::make('contacts_count')
                    ->label('Contacts')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('editRules')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('warning')
                    ->hidden(fn (Segment $record): bool => $record->trashed())
                    ->url(fn (Segment $record): string => SegmentResource::getUrl('rules', ['record' => $record])),

                DeleteAction::make(),

                RestoreAction::make()
                    ->after(fn (Segment $record) => SegmentResource::resyncAfterRestore($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    RestoreBulkAction::make()
                        ->after(fn (Collection $records) => $records->each(fn (Segment $record) => SegmentResource::resyncAfterRestore($record))),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No segments yet')
            ->emptyStateDescription('Create a segment to group contacts matching your rules.')
            ->emptyStateIcon(Heroicon::OutlinedQueueList);
    }
}
