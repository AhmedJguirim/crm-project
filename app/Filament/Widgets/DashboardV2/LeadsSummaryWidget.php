<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Support\Reports\ContactListLink;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportStats;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard summary of the leads, the contacts with the status Lead: the ones created in the page's period and still
 * leads (compared with the previous period), all the leads open right now, and how many of the contacts created in the
 * period are now past the Lead status (Prospect or further; a contact without a status is not counted, and with no
 * status history one created straight as a Prospect counts too). The owner filter applies to the contact owner.
 */
class LeadsSummaryWidget extends DashboardStatsWidget
{
    protected int|array|null $columns = ['@xl' => 3, '!@lg' => 3];

    protected function getHeading(): ?string
    {
        return 'Leads summary';
    }

    protected function getStats(): array
    {
        $filters = $this->filters();
        $period = $filters->period;
        $contacts = fn (): Builder => Contact::query()->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId));
        $newLeads = fn (ReportPeriod $of): int => $contacts()
            ->where('status', ContactStatus::Lead->value)
            ->whereBetween('created_at', $of->bounds())
            ->count();

        $current = $newLeads($period);
        $previous = $newLeads($period->previous());
        $created = $contacts()->whereBetween('created_at', $period->bounds())->count();
        $converted = $contacts()
            ->whereBetween('created_at', $period->bounds())
            ->whereIn('status', array_column(array_filter(ContactStatus::cases(), fn (ContactStatus $status): bool => $status !== ContactStatus::Lead), 'value'))
            ->count();

        return [
            ReportStats::compared(
                Stat::make('New Leads '.$period->title(), (string) $current),
                $current,
                $previous,
                (string) $previous,
            )->url(ContactListLink::newLeads($filters)),

            Stat::make('Open Leads', (string) $contacts()->where('status', ContactStatus::Lead->value)->count())
                ->description('All the leads right now, whatever their age')
                ->url(ContactListLink::openLeads($filters)),

            Stat::make('Past Lead Status', (string) $converted)
                ->description($created === 0 ? 'No contact created '.$period->phrase() : 'Prospect or further, of '.$created.' contacts created '.$period->phrase())
                ->color($converted > 0 ? 'success' : 'gray'),
        ];
    }
}
