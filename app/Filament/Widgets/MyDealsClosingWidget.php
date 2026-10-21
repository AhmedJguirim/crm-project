<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\Deal;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The logged-in user's open deals whose expected close date is in the past or in the current local week (Monday to
 * Sunday, organization timezone), oldest date first. In an organization with a single member, unassigned deals are
 * listed too, because nobody else can work them; in a team they are only counted in the description.
 */
class MyDealsClosingWidget extends TableWidget
{
    use SanitizesTableSearch;

    protected static ?int $sort = -39;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $solo = $this->isSoloOrganization();

        return $table
            ->query(fn (): Builder => $this->dealsQuery($solo))
            ->heading('My deals closing')
            ->description($this->description($solo))
            ->columns([
                TextColumn::make('title')
                    ->label('Deal')
                    ->limit(50)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 50 ? $state : null)
                    ->url(fn (Deal $record): ?string => DealResource::canView($record)
                        ? DealResource::getUrl('view', ['record' => $record])
                        : null),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('—'),

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
                    ->badge()
                    ->color(fn (Deal $record): string => $record->isOverdue() ? 'danger' : 'warning'),
            ])
            ->headerActions([
                Action::make('seeAll')
                    ->label('See all my deals')
                    ->link()
                    ->url($solo
                        ? DealResource::getUrl('index')
                        : DealResource::getUrl('index', ['tab' => 'mine'])),
            ])
            ->paginated([5])
            ->emptyStateHeading('No deals closing this week')
            ->emptyStateDescription('You have no open deals past their expected close date or closing this week.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * Solo means exactly one member in the current organization; this is decided by the member count, not by the
     * personal flag, because a personal organization can invite members.
     */
    protected function isSoloOrganization(): bool
    {
        return CurrentOrganization::find()?->members()->count() === 1;
    }

    /**
     * Open deals closing by the end of the current local week, oldest close date first; a team member only sees
     * the deals they own, a solo user also the unassigned ones.
     *
     * @return Builder<Deal>
     */
    protected function dealsQuery(bool $solo): Builder
    {
        $userId = Auth::id();

        return $this->closingByEndOfWeek()
            ->where(function (Builder $query) use ($userId, $solo): void {
                $query->where('owner_id', $userId);

                if ($solo) {
                    $query->orWhereNull('owner_id');
                }
            })
            ->with(['contact', 'pipelineStage'])
            ->orderBy('expected_close_date')
            ->orderBy('id');
    }

    /**
     * Open deals with an expected close date up to the local Sunday of the current week; every earlier date counts
     * as late. The close date is a date column, so it is compared with the local Sunday as a `Y-m-d` string.
     *
     * @return Builder<Deal>
     */
    protected function closingByEndOfWeek(): Builder
    {
        return Deal::query()
            ->open()
            ->whereNotNull('expected_close_date')
            ->where('expected_close_date', '<=', LocalTime::periodDates('week')[1]);
    }

    protected function description(bool $solo): string
    {
        if ($solo) {
            return 'Your open deals past their expected close date or closing this week, including unassigned ones.';
        }

        $unassigned = $this->closingByEndOfWeek()->whereNull('owner_id')->count();

        return match (true) {
            $unassigned === 0 => 'Your open deals past their expected close date or closing this week.',
            $unassigned === 1 => 'Your open deals past their expected close date or closing this week. 1 unassigned deal is past its close date or closing this week.',
            default => "Your open deals past their expected close date or closing this week. {$unassigned} unassigned deals are past their close date or closing this week.",
        };
    }
}
