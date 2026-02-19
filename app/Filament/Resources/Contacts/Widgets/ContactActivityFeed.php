<?php

namespace App\Filament\Resources\Contacts\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class ContactActivityFeed extends Widget
{
    use WithPagination;

    public ?Model $record = null;

    protected string $view = 'filament.resources.contacts.widgets.contact-activity-feed';

    protected int|string|array $columnSpan = 2;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    protected int $perPage = 15;

    #[On('activityLogged')]
    public function activityLogged(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $activities = $this->record
            ?->activities()
            ->with('user')
            ->when($this->dateFrom, fn ($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage);

        return [
            'activities' => $activities ?? collect(),
            'hasFilters' => filled($this->dateFrom) || filled($this->dateTo),
        ];
    }
}
