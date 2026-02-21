<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Activity;
use Filament\Widgets\Widget;

class RecentActivitiesWidget extends Widget
{
    protected string $view = 'filament.widgets.recent-activities-widget';

    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    protected function getViewData(): array
    {
        $activities = Activity::query()
            ->with('contact')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return [
            'activities' => $activities,
            'contactsUrl' => ContactResource::getUrl('index'),
        ];
    }
}
