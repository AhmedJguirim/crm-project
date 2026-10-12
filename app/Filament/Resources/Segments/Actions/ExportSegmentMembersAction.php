<?php

namespace App\Filament\Resources\Segments\Actions;

use App\Filament\Actions\Concerns\QueuesExports;
use App\Jobs\ExportContactsJob;
use App\Models\Contact;
use App\Models\Segment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Exports the members a published segment has now, as the contacts export: the same file, columns and delivery.
 * The members are the stored ones (the "Members" tab), the segment's rules are not run again.
 */
class ExportSegmentMembersAction
{
    use QueuesExports;

    public static function make(): Action
    {
        return Action::make('exportMembers')
            ->authorize(fn (): bool => Gate::allows('export', Contact::class))
            ->label('Export members')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Export segment members')
            ->modalDescription('Exports the contacts that are members of this segment now.')
            ->visible(fn (Segment $record): bool => $record->is_published)
            ->disabled(fn (Segment $record): bool => $record->is_syncing)
            ->tooltip(fn (Segment $record): ?string => $record->is_syncing ? 'Wait until the members are up to date.' : null)
            ->schema([self::exportFormatField()])
            ->action(function (array $data, Segment $record): void {
                $ids = $record->contacts()
                    ->orderBy('contacts.name')
                    ->orderBy('contacts.id')
                    ->pluck('contacts.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                self::queueExport($ids, self::exportFormatOf($data), self::fileLabel($record), ExportContactsJob::class);
            });
    }

    /**
     * `segment-<slug>`, the slug cut at 60 characters so the download name stays within what the download route accepts.
     */
    private static function fileLabel(Segment $segment): string
    {
        $slug = rtrim(Str::limit(Str::slug($segment->name), 60, ''), '-');

        return $slug === '' ? 'segment' : "segment-{$slug}";
    }
}
