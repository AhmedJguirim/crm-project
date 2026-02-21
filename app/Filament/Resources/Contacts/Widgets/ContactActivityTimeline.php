<?php

namespace App\Filament\Resources\Contacts\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class ContactActivityTimeline extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.contacts.widgets.contact-activity-timeline';

    protected int|string|array $columnSpan = 'full';

    #[On('activityLogged')]
    public function refreshActivities(): void
    {
        // Livewire re-renders, Alpine picks up new JSON data
    }

    /**
     * Called by Alpine.js via $wire to get fresh chart data after events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getChartActivities(): array
    {
        return $this->buildActivityData();
    }

    protected function getViewData(): array
    {
        $data = $this->buildActivityData();

        return [
            'activitiesJson' => json_encode($data),
            'hasActivities' => count($data) > 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildActivityData(): array
    {
        if (! $this->record) {
            return [];
        }

        return $this->record
            ->activities()
            ->with('user')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn ($activity): array => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'type_label' => $activity->type->getLabel(),
                'occurred_at' => $activity->occurred_at->toIso8601String(),
                'occurred_at_formatted' => $activity->occurred_at->format('M j, Y \a\t g:i A'),
                'duration_minutes' => $activity->duration_minutes,
                'subject' => $activity->subject,
                'notes' => $activity->notes,
                'outcome_label' => $activity->outcome?->getLabel(),
                'user_name' => $activity->user?->name,
            ])
            ->values()
            ->toArray();
    }
}
