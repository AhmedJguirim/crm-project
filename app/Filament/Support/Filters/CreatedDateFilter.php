<?php

namespace App\Filament\Support\Filters;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Created Date" range filter of a list: records created between two local days (organization timezone, end
 * inclusive), the rule of the dashboard "new …" stats (BUG-74).
 */
final class CreatedDateFilter
{
    public static function make(string $recordNoun): Filter
    {
        return Filter::make('created_between')
            ->label('Created Date')
            ->schema([
                DatePicker::make('from')->label('From'),
                DatePicker::make('until')->label('Until'),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $from = LocalTime::boundedFilterDate($data['from'] ?? null);
                $until = LocalTime::boundedFilterDate($data['until'] ?? null);
                $column = $query->qualifyColumn('created_at');

                return $query
                    ->when($from !== null, fn (Builder $builder): Builder => $builder->where($column, '>=', LocalTime::dayBounds($from)[0]))
                    ->when($until !== null, fn (Builder $builder): Builder => $builder->where($column, '<=', LocalTime::dayBounds($until)[1]));
            })
            ->indicateUsing(function (array $data) use ($recordNoun): array {
                $indicators = [];

                foreach (['from' => 'Created from', 'until' => 'Created until'] as $bound => $label) {
                    $date = LocalTime::boundedFilterDate($data[$bound] ?? null);

                    if ($date === null) {
                        continue;
                    }

                    $indicators[] = Indicator::make($label.' '.CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('M j, Y'))->removeField($bound);
                }

                $from = LocalTime::boundedFilterDate($data['from'] ?? null);
                $until = LocalTime::boundedFilterDate($data['until'] ?? null);

                if ($from !== null && $until !== null && $from > $until) {
                    $indicators[] = Indicator::make('From is after Until — no '.$recordNoun.' can match')->color('warning');
                }

                return $indicators;
            });
    }
}
