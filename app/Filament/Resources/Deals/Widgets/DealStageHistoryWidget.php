<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Models\DealStageChange;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\WithPagination;

/**
 * Lists the recorded stage changes of a deal, newest first.
 */
class DealStageHistoryWidget extends Widget
{
    use WithPagination;

    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-stage-history-widget';

    protected int|string|array $columnSpan = 'full';

    protected int $perPage = 10;

    /**
     * Goes back to the first page after a page action moved the deal, so the new row is visible.
     */
    #[On('dealUpdated')]
    public function refreshHistory(): void
    {
        $this->resetPage('stageHistoryPage');
    }

    protected function getViewData(): array
    {
        $changes = DealStageChange::query()
            ->where('deal_id', $this->record?->getKey() ?? 0)
            ->with(['fromStage', 'toStage', 'changedBy'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, pageName: 'stageHistoryPage');

        return [
            'changes' => $changes,
        ];
    }
}
