<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\NullsLastSort;
use App\Models\Company;
use App\Models\Deal;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The deals linked to a company (FEAT-31), read-only: open deals first, then by expected close date, each row opening
 * the deal. Linking a deal to a company stays in the deal form.
 */
class DealsRelationManager extends RelationManager
{
    use SanitizesTableSearch;

    protected static string $relationship = 'deals';

    /**
     * The number of deals of the company; no badge when there are none.
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Company $ownerRecord */
        $count = $ownerRecord->deals()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['contact', 'owner.organizations']))
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('stage')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('value')
                    ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD')
                    ->placeholder('—')
                    ->sortable(query: NullsLastSort::column('value')),

                TextColumn::make('expected_close_date')
                    ->label('Expected Close')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Deal $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(query: NullsLastSort::column('expected_close_date')),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('No contact'),

                TextColumn::make('owner_id')
                    ->label('Deal owner')
                    ->state(fn (Deal $record): ?string => $record->ownerLabel())
                    ->placeholder('No owner'),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("case when deals.status = 'open' then 0 else 1 end")
                ->orderByRaw('deals.expected_close_date asc nulls last')
                ->orderByDesc('deals.created_at'))
            ->filters([
                OptionSelectFilter::make('status')
                    ->options(DealStatus::class),
            ])
            ->recordUrl(fn (Deal $record): string => DealResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No deals for this company')
            ->emptyStateDescription('Deals linked to this company in the deal form appear here.');
    }
}
