<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All tasks'),
            'mine' => Tab::make('My tasks')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('owner_id', Auth::id())),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New Task'),
        ];
    }
}
