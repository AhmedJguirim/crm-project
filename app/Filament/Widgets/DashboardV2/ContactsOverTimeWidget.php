<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard line chart of the contacts added over time, of the page's period (owner filter on the contact owner).
 */
class ContactsOverTimeWidget extends CreatedOverTimeChartWidget
{
    protected ?string $heading = 'Contacts added over time';

    protected ?string $maxHeight = '170px';

    protected function recordsFor(?int $ownerId): Builder
    {
        return Contact::query()->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));
    }

    protected function seriesLabel(): string
    {
        return 'Contacts added';
    }

    protected function color(): string
    {
        return '#3b82f6';
    }
}
