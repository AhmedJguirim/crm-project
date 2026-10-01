<?php

namespace App\Filament\Resources\Segments\Widgets;

use App\Models\Contact;
use App\Models\Segment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class SegmentStatsOverview extends StatsOverviewWidget
{
    /** @var Segment|null */
    public ?Model $record = null;

    protected function getStats(): array
    {
        $segment = $this->record;

        $members = $segment->contacts()->count();
        $allContacts = Contact::query()->where('organization_id', $segment->organization_id)->count();
        $coverage = $allContacts > 0 ? $members / $allContacts * 100 : 0;

        $joinedLast30Days = $this->joinedBetween($segment, now()->subDays(30), now());
        $joinedPrevious30Days = $this->joinedBetween($segment, now()->subDays(60), now()->subDays(30));
        $isGrowing = $joinedLast30Days >= $joinedPrevious30Days;

        return [
            Stat::make('Members', Number::format($members))
                ->description($segment->is_published ? 'Contacts currently in the segment' : 'Publish the segment to compute its members')
                ->icon(Heroicon::OutlinedUsers),

            Stat::make('Coverage', Number::percentage($coverage, maxPrecision: 1))
                ->description('Of all '.Number::format($allContacts).' contacts')
                ->icon(Heroicon::OutlinedChartPie),

            Stat::make('Joined (30 days)', Number::format($joinedLast30Days))
                ->description(Number::format($joinedPrevious30Days).' in the previous 30 days')
                ->descriptionIcon($isGrowing ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->color($isGrowing ? 'success' : 'danger'),
        ];
    }

    private function joinedBetween(Segment $segment, mixed $from, mixed $to): int
    {
        return $segment->contacts()
            ->wherePivot('created_at', '>=', $from)
            ->wherePivot('created_at', '<', $to)
            ->count();
    }
}
