<?php

namespace App\Filament\Widgets;

use App\Enums\ContactStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

class ContactsOverviewWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = -17;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        $totalContacts = Contact::query()->count();
        $newThisMonth = Contact::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $supportsStatusColumn = Schema::hasColumn('contacts', 'status');

        $activeClients = $supportsStatusColumn
            ? Contact::query()->where('status', ContactStatus::ActiveClient->value)->count()
            : 0;

        $leads = $supportsStatusColumn
            ? Contact::query()->where('status', ContactStatus::Lead->value)->count()
            : 0;

        return [
            Stat::make('Total Contacts', (string) $totalContacts)
                ->description('All contacts in this organization')
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary')
                ->url(ContactResource::getUrl('index')),

            Stat::make('Active Clients', (string) $activeClients)
                ->description($supportsStatusColumn ? 'Status: Active Client' : 'Contact status not configured')
                ->descriptionIcon(Heroicon::OutlinedBriefcase)
                ->color('success')
                ->url(ContactResource::getUrl('index').'?tableFilters[status][values][0]='.ContactStatus::ActiveClient->value),

            Stat::make('New This Month', (string) $newThisMonth)
                ->description('Contacts added this month')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('info')
                ->url(ContactResource::getUrl('index')),

            Stat::make('Leads', (string) $leads)
                ->description($supportsStatusColumn ? 'Status: Lead' : 'Contact status not configured')
                ->descriptionIcon(Heroicon::OutlinedUser)
                ->color('warning')
                ->url(ContactResource::getUrl('index').'?tableFilters[status][values][0]='.ContactStatus::Lead->value),
        ];
    }
}
