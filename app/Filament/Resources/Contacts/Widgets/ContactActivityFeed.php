<?php

namespace App\Filament\Resources\Contacts\Widgets;

use App\Filament\Support\Concerns\FiltersActivityFeed;
use App\Models\Contact;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class ContactActivityFeed extends Widget
{
    use FiltersActivityFeed;
    use WithPagination;

    public ?Model $record = null;

    protected string $view = 'filament.resources.contacts.widgets.contact-activity-feed';

    protected int|string|array $columnSpan = 2;

    protected int $perPage = 15;

    #[On('activityLogged')]
    public function activityLogged(): void
    {
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        /** @var Contact|null $contact */
        $contact = $this->record;

        $activities = $contact
            ? $this->applyFeedFilters($contact->activities()->getQuery()->with('user'))
                ->orderByDesc('occurred_at')
                ->paginate($this->perPage)
            : collect();

        return [
            ...$this->feedFilterViewData(),
            'activities' => $activities,
        ];
    }
}
