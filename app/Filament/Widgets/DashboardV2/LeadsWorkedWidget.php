<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\ContactStatus;
use App\Models\Activity;
use App\Models\Contact;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard summary of how much the open leads (contacts with the status Lead right now) were worked in the page's
 * period: the leads with at least one activity in the period, the ones without, and the activities logged on leads.
 * The owner filter applies to the contact owner. Only activities linked to the lead itself count (PRD D2); deal-only
 * and company-only activities do not.
 */
class LeadsWorkedWidget extends DashboardStatsWidget
{
    protected int|array|null $columns = ['@xl' => 3, '!@lg' => 3];

    protected function getHeading(): ?string
    {
        return 'Leads worked summary';
    }

    protected function getDescription(): ?string
    {
        return 'The leads open now, and the activities logged on them '.$this->filters()->period->phrase().'. Activities logged only on a deal or a company are not counted.';
    }

    protected function getStats(): array
    {
        $filters = $this->filters();
        $bounds = $filters->period->bounds();
        $leads = fn (): Builder => Contact::query()
            ->where('status', ContactStatus::Lead->value)
            ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId));

        $open = $leads()->count();
        $worked = $leads()->whereHas('activities', fn (Builder $query): Builder => $query->whereBetween('occurred_at', $bounds))->count();
        $activities = Activity::query()
            ->whereBetween('occurred_at', $bounds)
            ->whereHas('contact', fn (Builder $query): Builder => $query
                ->where('status', ContactStatus::Lead->value)
                ->when($filters->ownerId !== null, fn (Builder $contacts): Builder => $contacts->where('owner_id', $filters->ownerId)))
            ->count();

        return [
            Stat::make('Leads Worked', (string) $worked)
                ->description($open === 0 ? 'No open lead' : 'Of '.$open.' open leads')
                ->color($worked > 0 ? 'success' : 'gray'),
            Stat::make('Not Worked', (string) ($open - $worked))
                ->description('Open leads without an activity '.$filters->period->phrase())
                ->color($open - $worked > 0 ? 'warning' : 'gray'),
            Stat::make('Activities On Leads', (string) $activities),
        ];
    }
}
