<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\Deals\DealStageMover;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardResourcePage;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Components\CardFlex;

class DealPipeline extends BoardResourcePage
{
    protected static string $resource = DealResource::class;

    protected static ?string $title = 'Pipeline';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    public function board(Board $board): Board
    {
        return $board
            ->query($this->getBoardQuery())
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
                    TextEntry::make('subtitle')
                        ->hiddenLabel()
                        ->state(fn (Deal $record): ?string => $this->cardSubtitle($record))
                        ->size(TextSize::ExtraSmall)
                        ->color('gray')
                        ->limit(60)
                        ->tooltip(function (Deal $record): ?string {
                            $subtitle = $this->cardSubtitle($record);

                            return $subtitle !== null && mb_strlen($subtitle) > 60 ? $subtitle : null;
                        })
                        ->visible(fn (Deal $record): bool => $this->cardSubtitle($record) !== null),

                    CardFlex::make([
                        TextEntry::make('value')
                            ->hiddenLabel()
                            ->state(fn (Deal $record): ?string => $record->value !== null
                                ? Number::currency((float) $record->value, Filament::getTenant()?->currencyCode() ?? 'USD')
                                : null)
                            ->weight(FontWeight::Bold)
                            ->visible(fn (Deal $record): bool => $record->value !== null),

                        TextEntry::make('expected_close_date')
                            ->hiddenLabel()
                            ->date('M j, Y')
                            ->size(TextSize::Small)
                            ->color(fn (Deal $record): string => $record->isOverdue() ? 'danger' : 'gray')
                            ->visible(fn (Deal $record): bool => $record->expected_close_date !== null),
                    ])
                        ->justify('between')
                        ->align('center'),
                ]);
            })
            ->actions([
                Action::make('openDeal')
                    ->action(function (?Deal $record): void {
                        if ($record === null) {
                            return;
                        }

                        $this->redirect(DealResource::getUrl('view', ['record' => $record]));
                    }),
            ])
            ->cardAction('openDeal');
    }

    /**
     * The small grey line under the title: the contact's name, then the name of the contact's first live company by name.
     */
    protected function cardSubtitle(Deal $deal): ?string
    {
        $contact = $deal->contact;

        if ($contact === null) {
            return null;
        }

        $company = $contact->companies->first();

        return $company === null ? $contact->name : "{$contact->name} · {$company->name}";
    }

    /**
     * Moves the card and lets DealStageMover set the status and the won / lost dates, in one transaction.
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        $deal = Deal::query()
            ->find($cardId);

        if (! $deal) {
            return;
        }

        Gate::authorize('update', $deal);

        $stage = DealStage::from($targetColumnId);

        DB::transaction(function () use ($deal, $stage, $cardId, $targetColumnId, $afterCardId, $beforeCardId): void {
            parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);

            DealStageMover::move($deal->refresh(), $stage);
        });
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
                ->authorize('create')
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
            ->with([
                'contact.companies' => fn (BelongsToMany $query) => $query->orderBy('companies.name'),
                'creator',
            ]);
    }
}
