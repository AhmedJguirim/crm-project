<?php

namespace App\Filament\Support\Concerns;

use App\Enums\ActivityType;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The filters shared by the contact, deal and company activity feeds: a date range and a set of activity types.
 * Every value comes from the browser, so each is sanitised before it reaches the query or the view.
 */
trait FiltersActivityFeed
{
    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    /** Raw browser state (forged input possible); read it through selectedActivityTypes(). */
    public mixed $activityTypes = [];

    public function clearFilters(): void
    {
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->activityTypes = [];
        $this->resetFeedPage();
    }

    /**
     * Exchanges the two bounds, only when both are valid days and From is after To; otherwise does nothing.
     */
    public function swapDateRange(): void
    {
        if (! $this->rangeIsReversed(LocalTime::boundedFilterDate($this->dateFrom), LocalTime::boundedFilterDate($this->dateTo))) {
            return;
        }

        [$this->dateFrom, $this->dateTo] = [$this->dateTo, $this->dateFrom];
        $this->resetFeedPage();
    }

    /**
     * Any change of a filter goes back to the first page. Livewire calls `updated<TraitName>` on every update; it cannot
     * be called from the browser.
     */
    public function updatedFiltersActivityFeed(string $property): void
    {
        if (in_array(Str::before($property, '.'), ['dateFrom', 'dateTo', 'activityTypes'], true)) {
            $this->resetFeedPage();
        }
    }

    protected function feedPageName(): string
    {
        return 'page';
    }

    protected function resetFeedPage(): void
    {
        $this->resetPage($this->feedPageName());
    }

    /**
     * Both bounds are valid days (Y-m-d, compared as strings) and From is after To.
     */
    protected function rangeIsReversed(?string $from, ?string $to): bool
    {
        return $from !== null && $to !== null && $from > $to;
    }

    /**
     * The chosen types that are real `ActivityType` values; anything else (a scalar, a nested array, a label, a padded
     * value) is dropped.
     *
     * @return list<string>
     */
    protected function selectedActivityTypes(): array
    {
        if (! is_array($this->activityTypes)) {
            return [];
        }

        $types = [];

        foreach ($this->activityTypes as $value) {
            if (is_string($value) && ActivityType::tryFrom($value) !== null) {
                $types[] = $value;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyFeedFilters(Builder $query): Builder
    {
        $from = LocalTime::boundedFilterDate($this->dateFrom);
        $to = LocalTime::boundedFilterDate($this->dateTo);
        $types = $this->selectedActivityTypes();

        return $query
            ->when($from, fn (Builder $query, string $date): Builder => $query->where('occurred_at', '>=', LocalTime::dayBounds($date)[0]))
            ->when($to, fn (Builder $query, string $date): Builder => $query->where('occurred_at', '<=', LocalTime::dayBounds($date)[1]))
            ->when($types !== [], fn (Builder $query): Builder => $query->whereIn('type', $types));
    }

    /**
     * @return array{hasFilters: bool, rangeIsReversed: bool, earliestDate: string, latestDate: string, activityTypeOptions: list<ActivityType>, selectedActivityTypes: list<string>}
     */
    protected function feedFilterViewData(): array
    {
        $from = LocalTime::boundedFilterDate($this->dateFrom);
        $to = LocalTime::boundedFilterDate($this->dateTo);
        $types = $this->selectedActivityTypes();

        return [
            'hasFilters' => $from !== null || $to !== null || $types !== [],
            'rangeIsReversed' => $this->rangeIsReversed($from, $to),
            'earliestDate' => LocalTime::EARLIEST_FILTER_DATE,
            'latestDate' => LocalTime::LATEST_FILTER_DATE,
            'activityTypeOptions' => ActivityType::cases(),
            'selectedActivityTypes' => $types,
        ];
    }
}
