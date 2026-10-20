<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStatus;
use App\Filament\Actions\LogDealActivityAction;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealDrawerInfolist;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Filament\Support\RecordKeyField;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Support\NotificationText;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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
    use SanitizesTableSearch;

    protected static string $resource = DealResource::class;

    protected static ?string $title = 'Pipeline';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    public function board(Board $board): Board
    {
        return $board
            ->query($this->getBoardQuery())
            ->recordTitleAttribute('title')
            ->columnIdentifier('pipeline_stage_id')
            ->positionIdentifier('position')
            ->columns($this->getStageColumns())
            ->searchable(['title'])
            ->filters([
                OptionSelectFilter::make('status')
                    ->options(DealStatus::class),

                RecordKeySelectFilter::make('contact_id')
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
                                ? Number::currency((float) $record->value, CurrentOrganization::find()?->currencyCode() ?? 'USD')
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

                    TextEntry::make('time_in_stage')
                        ->hiddenLabel()
                        ->state(fn (Deal $record): ?string => ($duration = $record->timeInStageForHumans()) === null ? null : "{$duration} in stage")
                        ->size(TextSize::ExtraSmall)
                        ->color('gray')
                        ->visible(fn (Deal $record): bool => $record->timeInStageForHumans() !== null),
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

                $this->lostReasonAction(),
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
     * The follow-up of a drag into Lost: asks for the optional reason and note and saves them on the lost deal; closing it or "Skip" leaves the deal lost without a reason.
     */
    protected function lostReasonAction(): Action
    {
        return Action::make('lostReason')
            ->authorize(fn (?Deal $record): bool => $this->canOnDrawerDeal('update', $record))
            ->modalHeading('Why was this deal lost?')
            ->modalDescription('The deal is now lost. A reason and a note are optional.')
            ->modalWidth(Width::Medium)
            ->schema(DealForm::lostReasonFields())
            ->modalSubmitActionLabel('Save reason')
            ->modalCancelActionLabel('Skip')
            ->action(function (array $data, Deal $record): void {
                if ($record->status !== DealStatus::Lost) {
                    Notification::make()
                        ->title('This deal is no longer lost')
                        ->body('Nothing was changed.')
                        ->warning()
                        ->send();

                    return;
                }

                $reasonId = filled($data['lost_reason_id'] ?? null) ? (int) $data['lost_reason_id'] : null;
                $note = $data['lost_reason_note'] ?? null;

                if ($reasonId === null && $note === null) {
                    return;
                }

                $record->update(['lost_reason_id' => $reasonId, 'lost_reason_note' => $note]);

                Notification::make()
                    ->title('Lost reason saved')
                    ->success()
                    ->send();
            });
    }

    /**
     * Moves the open deal to another stage through moveDealCard() (so the drag prompt never opens), with the optional lost reason and note when the target is Lost, unless it was moved by someone else since the form opened.
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
                'pipeline_stage_id' => $record->pipeline_stage_id,
                'from_pipeline_stage_id' => $record->pipeline_stage_id,
            ])
            ->schema([
                DealForm::stageSelect()->live(),

                ...array_map(
                    fn (Component $field): Component => $field->visible(fn (Get $get): bool => DealForm::isLostStage($get('pipeline_stage_id'))),
                    DealForm::lostReasonFields(),
                ),

                RecordKeyField::hidden('from_pipeline_stage_id'),
            ])
            ->action(function (array $data, Deal $record): void {
                if ((int) $record->pipeline_stage_id !== (int) $data['from_pipeline_stage_id']) {
                    Notification::make()
                        ->title('This deal was moved meanwhile')
                        ->body(NotificationText::plain("It is now in {$record->pipelineStage->name}. Nothing was changed."))
                        ->warning()
                        ->send();

                    return;
                }

                if ((int) $record->pipeline_stage_id === (int) $data['pipeline_stage_id']) {
                    return;
                }

                $target = DefaultPipeline::stageById($record->organization_id, $data['pipeline_stage_id']);

                $this->moveDealCard(
                    (string) $record->getKey(),
                    (string) $target->getKey(),
                    lostReasonId: filled($data['lost_reason_id'] ?? null) ? (int) $data['lost_reason_id'] : null,
                    lostReasonNote: $data['lost_reason_note'] ?? null,
                );

                Notification::make()
                    ->title(NotificationText::plain("Deal moved to {$target->name}"))
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
                $record->update([...$data, ...DealStageMover::attributesFor(DefaultPipeline::stageById($record->organization_id, $data['pipeline_stage_id']), $record)]);

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
     * Moves the dragged card, then asks for the optional lost reason when the drag made the deal lost.
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        $deal = $this->moveDealCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);

        if ($deal !== null && $deal->status === DealStatus::Lost && $deal->wasChanged('status')) {
            $this->mountAction('lostReason', [], ['recordKey' => (string) $deal->getKey()]);
        }
    }

    /**
     * Moves the card and lets DealStageMover set the status and the won / lost dates, in one transaction; ignores a forged card, neighbour or column, and refuses a user who may not update the deal with a notification instead of a 403. Returns the moved deal, or null when nothing was moved. A Lost target with a reason or a note saves them in the same write.
     */
    protected function moveDealCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null,
        ?int $lostReasonId = null,
        ?string $lostReasonNote = null
    ): ?Deal {
        $stage = self::isDealKey($targetColumnId)
            ? PipelineStage::forOrganization(CurrentOrganization::get()->id)
                ->where('pipeline_id', DefaultPipeline::ensureFor(CurrentOrganization::get())->getKey())
                ->find($targetColumnId)
            : null;

        if (
            $stage === null
            || ! self::isDealKey($cardId)
            || ($afterCardId !== null && ! self::isDealKey($afterCardId))
            || ($beforeCardId !== null && ! self::isDealKey($beforeCardId))
        ) {
            return null;
        }

        $deal = Deal::query()
            ->find($cardId);

        if (! $deal) {
            return null;
        }

        if (! Gate::allows('update', $deal)) {
            Notification::make()
                ->title("You can't move this deal")
                ->body('Nothing was changed.')
                ->danger()
                ->send();

            return null;
        }

        DB::transaction(function () use ($deal, $stage, $cardId, $targetColumnId, $afterCardId, $beforeCardId, $lostReasonId, $lostReasonNote): void {
            parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);

            $deal->refresh();

            if ($stage->type === DealStatus::Lost && ($lostReasonId !== null || $lostReasonNote !== null)) {
                DealStageMover::moveToLost($deal, $lostReasonId, $lostReasonNote);

                return;
            }

            DealStageMover::move($deal, $stage);
        });

        return $deal;
    }

    /** @return array<int, Column> */
    protected function getStageColumns(): array
    {
        return DefaultPipeline::stagesOf(CurrentOrganization::get()->id)
            ->map(fn (PipelineStage $stage): Column => Column::make((string) $stage->getKey())
                ->label($stage->name)
                ->color($stage->color))
            ->all();
    }

    /** @var array<string, array{text: string, hint: ?string}>|null */
    protected ?array $cachedBoardColumnSummaries = null;

    /**
     * The line under a column header: the total value of the column's deals and, for an open stage, that total weighted by the stage's win probability. Same deals as the count badge (search and filters applied). Null for an unknown column.
     *
     * @return array{text: string, hint: ?string}|null
     */
    public function getBoardColumnSummary(string $columnId): ?array
    {
        return ($this->cachedBoardColumnSummaries ??= $this->buildBoardColumnSummaries())[$columnId] ?? null;
    }

    /**
     * One grouped aggregate over the filtered board query, formatted per stage of the default pipeline.
     *
     * @return array<string, array{text: string, hint: ?string}>
     */
    protected function buildBoardColumnSummaries(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

        $totals = $this->getFilteredTableQuery()
            ->select('pipeline_stage_id')
            ->selectRaw('COALESCE(SUM(value), 0) as total_value')
            ->groupBy('pipeline_stage_id')
            ->pluck('total_value', 'pipeline_stage_id');

        return DefaultPipeline::stagesOf(CurrentOrganization::get()->id)
            ->mapWithKeys(function (PipelineStage $stage) use ($totals, $currency): array {
                $total = (float) ($totals[$stage->getKey()] ?? 0);
                $text = 'Total '.Number::currency($total, $currency);

                if ($stage->type !== DealStatus::Open) {
                    return [(string) $stage->getKey() => ['text' => $text, 'hint' => null]];
                }

                return [(string) $stage->getKey() => [
                    'text' => $text.' · Weighted '.Number::currency($stage->weightedValue($total), $currency),
                    'hint' => "Weighted: the total × {$stage->probability}% win probability",
                ]];
            })
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Deal::class)
                ->authorize('create')
                ->label('New Deal')
                ->modalHeading('New deal')
                ->createAnother(false)
                ->schema(fn (Schema $schema): Schema => DealForm::configure($schema))
                ->mutateDataUsing(fn (array $data): array => DealForm::mutateDataBeforeCreate($data))
                ->successNotificationTitle('Deal created successfully'),

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
            ->where('organization_id', CurrentOrganization::get()->id)
            ->with(['contact', 'company', 'creator', 'pipelineStage', 'latestStageChange']);
    }
}
