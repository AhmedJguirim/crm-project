<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardResourcePage;
use Relaticle\Flowforge\Column;

class DealPipeline extends BoardResourcePage
{
    protected static string $resource = DealResource::class;

    protected static ?string $title = 'Pipeline';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    public function board(Board $board): Board
    {
        return $board
            ->query(
                Deal::query()
                    ->where('organization_id', Filament::getTenant()->id)
                    ->with(['contact', 'creator'])
            )
            ->recordTitleAttribute('title')
            ->columnIdentifier('stage')
            ->positionIdentifier('position')
            ->columns($this->getStageColumns())
            ->searchable(['title'])
            ->filters([
                SelectFilter::make('status')
                    ->options(DealStatus::class),

                SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->cardSchema(function (Schema $schema): Schema {
                return $schema->schema([
                    TextEntry::make('contact.name')
                        ->label('Contact')
                        ->placeholder('No contact')
                        ->url(fn (Deal $record): ?string => $record->contact
                            ? ContactResource::getUrl('view', ['record' => $record->contact])
                            : null),

                    TextEntry::make('value')
                        ->label('Value')
                        ->state(fn (Deal $record): string => $record->value
                            ? number_format((float) $record->value, 2).' '.$record->currency
                            : '—'),

                    TextEntry::make('expected_close_date')
                        ->label('Close Date')
                        ->date('M j, Y')
                        ->placeholder('—')
                        ->color(fn (Deal $record): ?string => $record->expected_close_date?->isPast() ? 'danger' : null),
                ]);
            })
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Deal $record): string => DealResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),

                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Deal $record): string => DealResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ]);
    }

    /**
     * Override moveCard to sync status/won_at/lost_at when stage changes.
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);

        $deal = Deal::query()
            ->find($cardId);

        if (! $deal) {
            return;
        }

        $stage = DealStage::from($targetColumnId);

        $updates = match ($stage) {
            DealStage::Won => [
                'status' => DealStatus::Won,
                'won_at' => $deal->won_at ?? now(),
                'lost_at' => null,
            ],
            DealStage::Lost => [
                'status' => DealStatus::Lost,
                'lost_at' => $deal->lost_at ?? now(),
                'won_at' => null,
            ],
            default => [
                'status' => DealStatus::Open,
                'won_at' => null,
                'lost_at' => null,
            ],
        };

        $deal->update($updates);
    }

    /** @return array<int, Column> */
    protected function getStageColumns(): array
    {
        return array_map(
            fn (DealStage $stage): Column => Column::make($stage->value)
                ->label($stage->getLabel() ?? $stage->value)
                ->color($stage->getColor() ?? 'gray')
                ->icon($stage->getIcon()),
            DealStage::cases()
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Deal')
                ->url(DealResource::getUrl('create')),

            Action::make('tableView')
                ->label('Table View')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(DealResource::getUrl('index')),
        ];
    }

    public function getBoardQuery(): ?Builder
    {
        return Deal::query()
            ->where('organization_id', Filament::getTenant()->id)
            ->with(['contact', 'creator']);
    }
}
