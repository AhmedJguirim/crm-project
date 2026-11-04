<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard funnel of the contacts created in the page's period, by the status they have now, in the order of the
 * ContactStatus enum (Lead, Prospect, Active Client, Past Client, Partner), then the contacts without a status (the
 * status is optional). The CRM keeps no status history, so a contact counts only under its current status. Owner
 * filter on the contact owner.
 */
class LeadsFunnelWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Leads funnel';

    protected ?string $emptyStateHeading = 'No new contacts';

    public function getDescription(): string|Htmlable|null
    {
        return 'Contacts created '.$this->filters()->period->phrase().', by the status they have now.';
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
                ->whereBetween('created_at', $filters->period->bounds())
                ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId)),
            'status',
        );

        $ordered = [];

        foreach (ContactStatus::cases() as $status) {
            $ordered[$status->getLabel()] = $counts[$status->value] ?? 0;
            unset($counts[$status->value]);
        }

        $ordered['No status'] = array_sum($counts);

        $data = $this->categories($ordered, 'Contacts', sorted: false);

        if ($data !== []) {
            $data['datasets'][0]['backgroundColor'] = self::PALETTE[0];
        }

        return $data;
    }

    protected function getOptions(): array
    {
        return $this->horizontalBarOptions();
    }
}
