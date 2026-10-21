<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\Deal;
use App\Services\Reports\DealsNeedingAttention;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard table of the open deals that need a push (see DealsNeedingAttention), each with its reasons as badges. It
 * follows the dashboard's owner filter; the period does not apply because the list is the state as of now.
 */
class DealsNeedingAttentionWidget extends TableWidget
{
    use InteractsWithPageFilters;
    use SanitizesTableSearch;

    protected static ?int $sort = -25;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->dealsQuery())
            ->heading('Deals needing attention')
            ->description('Open deals past their close date, without a pending task, without activity for '.DealsNeedingAttention::INACTIVITY_DAYS.' days, or in the same stage for over '.DealsNeedingAttention::STAGE_DAYS.' days.')
            ->columns([
                TextColumn::make('title')
                    ->label('Deal')
                    ->limit(50)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 50 ? $state : null)
                    ->url(fn (Deal $record): ?string => DealResource::canView($record)
                        ? DealResource::getUrl('view', ['record' => $record])
                        : null),

                TextColumn::make('reasons')
                    ->label('Why')
                    ->state(fn (Deal $record): array => DealsNeedingAttention::reasonsFor($record))
                    ->badge(),

                TextColumn::make('owner_id')
                    ->label('Owner')
                    ->state(fn (Deal $record): ?string => $record->ownerLabel())
                    ->placeholder('Unassigned'),

                TextColumn::make('value')
                    ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                    ->placeholder('—'),

                TextColumn::make('pipelineStage.name')
                    ->label('Stage')
                    ->badge()
                    ->color(fn (Deal $record): ?string => $record->pipelineStage?->color),

                TextColumn::make('expected_close_date')
                    ->label('Expected close')
                    ->date('M j, Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('seeAll')
                    ->label('See all deals needing attention')
                    ->link()
                    ->url(DealResource::getUrl('index', ['tab' => 'attention'])),
            ])
            ->paginated([5])
            ->emptyStateHeading('No deals need attention')
            ->emptyStateDescription('Every open deal has a next step and recent activity, and none is late or stuck in its stage.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * @return Builder<Deal>
     */
    protected function dealsQuery(): Builder
    {
        $ownerId = DashboardFilters::fromPageFilters($this->pageFilters)->ownerId;

        return DealsNeedingAttention::withReasonFlags(DealsNeedingAttention::constrain(Deal::query()))
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('deals.owner_id', $ownerId))
            ->with(['owner.organizations', 'contact', 'pipelineStage'])
            ->orderBy('deals.expected_close_date')
            ->orderBy('deals.id');
    }
}
