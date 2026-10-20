<?php

namespace App\Filament\Resources\Contacts\Widgets;

use App\Models\Contact;
use App\Support\LocalTime;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Exchanges the two bounds, only when both are valid days and From is after To; otherwise does nothing.
     */
    public function swapDateRange(): void
    {
        if (! $this->rangeIsReversed(LocalTime::filterDate($this->dateFrom), LocalTime::filterDate($this->dateTo))) {
            return;
        }

        [$this->dateFrom, $this->dateTo] = [$this->dateTo, $this->dateFrom];
        $this->resetPage();
    }

    /**
     * Both bounds are valid days (Y-m-d, compared as strings) and From is after To.
     */
    protected function rangeIsReversed(?string $from, ?string $to): bool
    {
        return $from !== null && $to !== null && $from > $to;
    }

    protected function getViewData(): array
    {
        /** @var Contact|null $contact */
        $contact = $this->record;
        $from = LocalTime::filterDate($this->dateFrom);
        $to = LocalTime::filterDate($this->dateTo);

        $activities = $contact
            ?->activities()
            ->with('user')
            ->when($from, fn (Builder $query, string $date): Builder => $query->where('occurred_at', '>=', LocalTime::dayBounds($date)[0]))
            ->when($to, fn (Builder $query, string $date): Builder => $query->where('occurred_at', '<=', LocalTime::dayBounds($date)[1]))
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage);

        return [
            'activities' => $activities ?? collect(),
            'hasFilters' => $from !== null || $to !== null,
            'rangeIsReversed' => $this->rangeIsReversed($from, $to),
        ];
    }
}
