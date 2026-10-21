<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\DealsNeedingAttention;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListDeals extends ListRecords
{
    use SanitizesTableSearch;

    protected static string $resource = DealResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All deals'),
            'mine' => Tab::make('My deals')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('owner_id', Auth::id())),
            'attention' => Tab::make('Needs attention')
                ->modifyQueryUsing(fn (Builder $query): Builder => DealsNeedingAttention::constrain($query)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Deal'),

            Action::make('boardView')
                ->label('Board View')
                ->icon(Heroicon::OutlinedViewColumns)
                ->color('gray')
                ->url(DealResource::getUrl('pipeline')),
        ];
    }
}
