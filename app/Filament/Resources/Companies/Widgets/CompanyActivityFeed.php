<?php

namespace App\Filament\Resources\Companies\Widgets;

use App\Filament\Support\Concerns\FiltersActivityFeed;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class CompanyActivityFeed extends Widget
{
    use FiltersActivityFeed;
    use WithPagination;

    public ?Model $record = null;

    protected string $view = 'filament.resources.companies.widgets.company-activity-feed';

    protected int|string|array $columnSpan = 'full';

    protected int $perPage = 15;

    #[On('activityLogged')]
    public function activityLogged(): void
    {
        $this->resetFeedPage();
    }

    protected function feedPageName(): string
    {
        return 'activitiesPage';
    }

    /**
     * The company's own activities, its deals' and those of its live linked contacts, newest first, each listed once.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $companyId = $this->record?->getKey() ?? 0;

        $activities = $this->applyFeedFilters(Activity::query()
            ->where(function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereIn('deal_id', Deal::query()->select('id')->where('company_id', $companyId))
                    ->orWhereIn('contact_id', Contact::query()->select('contacts.id')->whereHas('companies', fn (Builder $companies): Builder => $companies->withTrashed()->whereKey($companyId)));
            })
            ->with(['user', 'contact', 'deal']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, pageName: $this->feedPageName());

        return [
            ...$this->feedFilterViewData(),
            'activities' => $activities,
        ];
    }
}
