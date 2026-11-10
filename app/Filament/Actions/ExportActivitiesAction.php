<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportActivitiesJob;
use App\Models\Activity;
use App\Models\Organization;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;

/**
 * The export of the organization's activities of a date range, offered on the contacts list (activities have no list
 * of their own). It queues an `ExportActivitiesJob`.
 */
class ExportActivitiesAction
{
    use QueuesExports;

    public static function make(): Action
    {
        return Action::make('exportActivities')
            ->model(Activity::class)
            ->authorize('export')
            ->label('Export activities')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export activities')
            ->modalDescription('Exports every activity of the organization, logged on contacts, deals or companies, that happened between the two dates (days in the organization\'s timezone).')
            ->schema([
                DatePicker::make('from')
                    ->label('From')
                    ->minDate(LocalTime::EARLIEST_FILTER_DATE)
                    ->maxDate(LocalTime::LATEST_FILTER_DATE),
                DatePicker::make('until')
                    ->label('Until')
                    ->minDate(LocalTime::EARLIEST_FILTER_DATE)
                    ->maxDate(LocalTime::LATEST_FILTER_DATE)
                    ->helperText('Leave a date empty for no limit on that side. If From is after Until, the two dates are swapped.'),
                self::exportFormatField(),
            ])
            ->action(function (array $data): void {
                $from = LocalTime::boundedFilterDate($data['from'] ?? null);
                $until = LocalTime::boundedFilterDate($data['until'] ?? null);

                if ($from !== null && $until !== null && $from > $until) {
                    [$from, $until] = [$until, $from];
                }

                $timezone = Organization::validTimezoneOrUtc(CurrentOrganization::get()->timezone);

                $ids = Activity::query()
                    ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', LocalTime::dayBounds($from, $timezone)[0]))
                    ->when($until !== null, fn ($query) => $query->where('occurred_at', '<=', LocalTime::dayBounds($until, $timezone)[1]))
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), 'activities', ExportActivitiesJob::class);
            });
    }
}
