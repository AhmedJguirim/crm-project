<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Models\Contact;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard bar chart of the leads (contacts with the status Lead) created in the page's period, by lead source
 * (owner filter on the contact owner). A lead without a known source counts as "Unknown source".
 */
class LeadsBySourceWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Leads by source';

    protected ?string $emptyStateHeading = 'No new leads';

    public function getDescription(): string|Htmlable|null
    {
        return 'Leads created '.$this->filters()->period->phrase().' that are still leads, by lead source.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $filters = $this->filters();

        $counts = DashboardBreakdowns::countsBy(
            Contact::query()
                ->where('status', ContactStatus::Lead->value)
                ->whereBetween('created_at', $filters->period->bounds())
                ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId)),
            'lead_source',
        );

        $labelled = [];

        foreach ($counts as $stored => $count) {
            $label = LeadSource::tryFrom($stored)?->getLabel() ?? 'Unknown source';
            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        $data = $this->categories($labelled, 'Leads');

        if ($data !== []) {
            $data['datasets'][0]['backgroundColor'] = self::PALETTE[2];
        }

        return $data;
    }

    protected function getOptions(): array
    {
        return $this->horizontalBarOptions();
    }
}
