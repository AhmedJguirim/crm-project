<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStatus;
use App\Enums\TaskStatus;
use App\Models\Deal;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DealDrawerInfolist
{
    public const int LIST_LIMIT = 3;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        TextEntry::make('pipelineStage.name')
                            ->label('Stage')
                            ->badge()
                            ->color(fn (?Deal $record): ?string => $record?->pipelineStage?->color),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),

                        TextEntry::make('value')
                            ->label('Value')
                            ->state(fn (?Deal $record): ?string => $record?->value !== null
                                ? Number::currency((float) $record->value, CurrentOrganization::find()?->currencyCode() ?? 'USD')
                                : null)
                            ->placeholder('—'),

                        TextEntry::make('expected_close_date')
                            ->label('Expected close')
                            ->date('M j, Y')
                            ->placeholder('—')
                            ->color(fn (?Deal $record): string => $record?->isOverdue() ? 'danger' : 'gray'),

                        TextEntry::make('owner')
                            ->label('Deal owner')
                            ->state(fn (?Deal $record): ?string => $record?->ownerLabel())
                            ->placeholder('No owner'),

                        TextEntry::make('lost_reason')
                            ->label('Lost reason')
                            ->state(fn (?Deal $record): ?string => $record?->lostReasonLabel())
                            ->placeholder('No reason given')
                            ->visible(fn (?Deal $record): bool => $record?->status === DealStatus::Lost),

                        TextEntry::make('lost_reason_note')
                            ->label('Lost note')
                            ->visible(fn (?Deal $record): bool => $record?->status === DealStatus::Lost && filled($record->lost_reason_note))
                            ->columnSpanFull(),

                        TextEntry::make('next_tasks')
                            ->label('Next tasks')
                            ->state(fn (?Deal $record): array => $record === null ? [] : self::nextTaskLines($record))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('No open tasks')
                            ->columnSpanFull(),

                        TextEntry::make('recent_activities')
                            ->label('Recent activities')
                            ->state(fn (?Deal $record): array => $record === null ? [] : self::recentActivityLines($record))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('No activities yet')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The deal's three nearest pending tasks, the ones without a due date last.
     *
     * @return array<int, string>
     */
    public static function nextTaskLines(Deal $deal): array
    {
        return $deal->tasks()
            ->where('status', TaskStatus::Pending->value)
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn ($task): string => $task->due_at === null
                ? "{$task->title} — no due date"
                : "{$task->title} — due ".LocalTime::format($task->due_at, 'M j, Y'))
            ->all();
    }

    /**
     * The deal's three latest activities, newest first.
     *
     * @return array<int, string>
     */
    public static function recentActivityLines(Deal $deal): array
    {
        return $deal->activities()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function ($activity): string {
                $line = "{$activity->type->getLabel()} · ".LocalTime::format($activity->occurred_at, 'M j, Y');

                return blank($activity->subject) ? $line : "{$line} · {$activity->subject}";
            })
            ->all();
    }
}
