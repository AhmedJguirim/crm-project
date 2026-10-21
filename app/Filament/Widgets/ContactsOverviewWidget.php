<?php

namespace App\Filament\Widgets;

use App\Enums\ContactStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Support\Reports\DashboardFilters;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard contact counts. The page's owner filter applies to every figure; the period does not (they are current counts).
 */
class ContactsOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = -17;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        $ownerId = DashboardFilters::fromPageFilters($this->pageFilters)->ownerId;
        $ownedBy = fn (Builder $query): Builder => $query->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));

        $totalContacts = Contact::query()->tap($ownedBy)->count();

        $supportsStatusColumn = Schema::hasColumn('contacts', 'status');

        $activeClients = $supportsStatusColumn
            ? Contact::query()->tap($ownedBy)->where('status', ContactStatus::ActiveClient->value)->count()
            : 0;

        $leads = $supportsStatusColumn
            ? Contact::query()->tap($ownedBy)->where('status', ContactStatus::Lead->value)->count()
            : 0;

        return [
            Stat::make('Total Contacts', (string) $totalContacts)
                ->description($ownerId === null ? 'All contacts in this organization' : 'Contacts of the selected owner')
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary')
                ->url(ContactResource::getUrl('index')),

            Stat::make('Active Clients', (string) $activeClients)
                ->description($supportsStatusColumn ? 'Status: Active Client' : 'Contact status not configured')
                ->descriptionIcon(Heroicon::OutlinedBriefcase)
                ->color('success')
                ->url(ContactResource::getUrl('index').'?tableFilters[status][values][0]='.ContactStatus::ActiveClient->value),

            Stat::make('Leads', (string) $leads)
                ->description($supportsStatusColumn ? 'Status: Lead' : 'Contact status not configured')
                ->descriptionIcon(Heroicon::OutlinedUser)
                ->color('warning')
                ->url(ContactResource::getUrl('index').'?tableFilters[status][values][0]='.ContactStatus::Lead->value),
        ];
    }
}
