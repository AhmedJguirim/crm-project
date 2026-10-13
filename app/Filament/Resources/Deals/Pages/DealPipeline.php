<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Actions\LogDealActivityAction;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealDrawerInfolist;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Models\Deal;
use App\Services\Deals\DealStageMover;
use App\Support\NotificationText;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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
                    ->authorize(fn (?Deal $record): bool => $this->canOnDrawerDeal('view', $record))
                    ->slideOver()
                    ->modalWidth(Width::Large)
                    ->modalHeading(fn (?Deal $record): string => (string) $record?->title)
                    ->modalDescription(fn (?Deal $record): ?string => $record === null ? null : $this->cardSubtitle($record))
                    ->schema(fn (Schema $schema): Schema => DealDrawerInfolist::configure($schema))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->extraModalFooterActions([
                        $this->moveStageAction(),
                        LogDealActivityAction::make()
                            ->after(fn (Action $action) => $this->refreshDrawerDeal($action)),
                        QuickTaskAction::makeForDeal()
                            ->after(fn (Action $action) => $this->refreshDrawerDeal($action)),
                        $this->editDealAction(),
                        Action::make('openFullPage')
                            ->label('Open full page')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->color('gray')
                            ->url(fn (?Deal $record): ?string => $record === null ? null : DealResource::getUrl('view', ['record' => $record])),
                    ]),
            ])
            ->cardAction('openDeal');
    }

    /**
     * True when the deal exists in this organization's board and the user may run the ability on it; a missing deal (a forged key) is refused.
     */
    protected function canOnDrawerDeal(string $ability, ?Deal $record): bool
    {
        return $record !== null && (Auth::user()?->can($ability, $record) ?? false);
    }

    /**
     * Reloads the drawer's deal after a child action, because the child works on its own instance of the deal.
     */
    protected function refreshDrawerDeal(Action $action): void
    {
        $action->getParentAction()?->getRecord()?->refresh();
    }

    /**
     * Reloads the drawer's deal and renders the whole page, because Filament otherwise re-renders only the modals while the drawer stays open, leaving the board stale.
     */
    protected function refreshDrawerDealAndBoard(Action $action): void
    {
        $this->refreshDrawerDeal($action);
        $this->forceRender();
    }

    /**
     * Moves the open deal to another stage through moveCard(), unless it was moved by someone else since the form opened.
     */
    protected function moveStageAction(): Action
    {
        return Action::make('moveStage')
            ->label('Move stage')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->authorize(fn (?Deal $record): bool => $this->canOnDrawerDeal('update', $record))
            ->modalHeading('Move deal')
            ->modalSubmitActionLabel('Move')
            ->fillForm(fn (Deal $record): array => [
                'stage' => $record->stage,
                'from_stage' => $record->stage->value,
            ])
            ->schema([
                Select::make('stage')
                    ->label('Stage')
                    ->options(DealStage::class)
                    ->required(),

                Hidden::make('from_stage'),
            ])
            ->action(function (array $data, Deal $record): void {
                $target = $data['stage'] instanceof DealStage ? $data['stage'] : DealStage::from($data['stage']);

                if ($record->stage->value !== $data['from_stage']) {
                    Notification::make()
                        ->title('This deal was moved meanwhile')
                        ->body(NotificationText::plain("It is now in {$record->stage->getLabel()}. Nothing was changed."))
                        ->warning()
                        ->send();

                    return;
                }

                if ($target === $record->stage) {
                    return;
                }

                $this->moveCard((string) $record->getKey(), $target->value);

                Notification::make()
                    ->title(NotificationText::plain("Deal moved to {$target->getLabel()}"))
                    ->success()
                    ->send();
            })
            ->after(fn (Action $action) => $this->refreshDrawerDealAndBoard($action));
    }

    /**
     * Edits the open deal with the deal form; the stage keeps deciding the status and the won / lost dates.
     */
    protected function editDealAction(): Action
    {
        return Action::make('editDeal')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize(fn (?Deal $record): bool => $this->canOnDrawerDeal('update', $record))
            ->modalHeading('Edit deal')
            ->modalSubmitActionLabel('Save changes')
            ->fillForm(fn (Deal $record): array => $record->attributesToArray())
            ->schema(fn (Schema $schema, ?Deal $record): Schema => $record === null ? $schema->components([]) : DealForm::configure($schema))
            ->action(function (array $data, Deal $record): void {
                $record->update([...$data, ...DealStageMover::attributesFor($data['stage'], $record)]);

                Notification::make()
                    ->title('Saved')
                    ->success()
                    ->send();
            })
            ->after(fn (Action $action) => $this->refreshDrawerDealAndBoard($action));
    }

    /**
     * The small grey line under the title: the contact's name and the deal's company name, whichever are live.
     */
    protected function cardSubtitle(Deal $deal): ?string
    {
        $parts = array_filter([$deal->contact?->name, $deal->company?->name], fn (?string $part): bool => $part !== null);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * A deal key a forged request could send: a positive integer within the bigint range.
     */
    protected static function isDealKey(mixed $key): bool
    {
        return filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    /**
     * Resolves a board action, dropping a malformed record key so the lookup never reaches the database.
     *
     * @param  array<string, mixed>  $action
     * @param  array<int, Action>  $parentActions
     */
    protected function resolveBoardAction(array $action, array $parentActions): ?Action
    {
        $key = $action['context']['recordKey'] ?? $action['arguments']['recordKey'] ?? null;

        if (filled($key) && ! self::isDealKey($key)) {
            $action = Arr::except($action, ['context.recordKey', 'arguments.recordKey']);
        }

        return parent::resolveBoardAction($action, $parentActions);
    }

    /**
     * Returns no record for a malformed key on the last mounted action, instead of querying the database with it.
     */
    public function getDefaultActionRecord(Action $action): ?Model
    {
        $mountedActions = $this->mountedActions ?? [];
        $current = end($mountedActions);
        $key = $current['context']['recordKey'] ?? $current['arguments']['recordKey'] ?? null;

        if (filled($key) && ! self::isDealKey($key)) {
            return null;
        }

        return parent::getDefaultActionRecord($action);
    }

    /**
     * Moves the card and lets DealStageMover set the status and the won / lost dates, in one transaction; ignores a forged card, neighbour or column.
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        $stage = DealStage::tryFrom($targetColumnId);

        if (
            $stage === null
            || ! self::isDealKey($cardId)
            || ($afterCardId !== null && ! self::isDealKey($afterCardId))
            || ($beforeCardId !== null && ! self::isDealKey($beforeCardId))
        ) {
            return;
        }

        $deal = Deal::query()
            ->find($cardId);

        if (! $deal) {
            return;
        }

        Gate::authorize('update', $deal);

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
            ->with(['contact', 'company', 'creator']);
    }
}
