<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\LeadSource;
use App\Models\Contact;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard doughnut of the contacts created in the page's period, by lead source (owner filter on the contact
 * owner). A contact without a source, or with one the LeadSource enum does not know, counts as "Unknown source".
 */
class ContactSourcesWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Contact sources';

    protected ?string $maxHeight = '330px';

    protected ?string $emptyStateHeading = 'No new contacts';

    public function getDescription(): string|Htmlable|null
    {
        return 'Contacts created '.$this->filters()->period->phrase().', by lead source.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $filters = $this->filters();

        $counts = DashboardBreakdowns::countsBy(
            Contact::query()
                ->whereBetween('created_at', $filters->period->bounds())
                ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId)),
            'lead_source',
        );

        $labelled = [];

        foreach ($counts as $stored => $count) {
            $label = LeadSource::tryFrom($stored)?->getLabel() ?? 'Unknown source';
            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        return $this->categories($labelled, 'Contacts');
    }

    protected function getOptions(): array
    {
        return $this->doughnutOptions();
    }
}
