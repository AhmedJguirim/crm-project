<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Filament\Support\Concerns\FiltersActivityFeed;
use App\Models\Activity;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class DealActivityFeed extends Widget
{
    use FiltersActivityFeed;
    use WithPagination;

    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-activity-feed';

    protected int|string|array $columnSpan = 2;

    protected int $perPage = 15;

    #[On('activityLogged')]
    public function activityLogged(): void
    {
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $activities = $this->applyFeedFilters(Activity::query()
            ->where('deal_id', $this->record?->getKey() ?? 0)
            ->with('user'))
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage);

        return [
            ...$this->feedFilterViewData(),
            'activities' => $activities,
        ];
    }
}
