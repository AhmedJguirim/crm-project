<?php

namespace App\Filament\Support;

use App\Services\Segments\SegmentUsage;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Keeps records referenced by segment conditions from being deleted through the UI, explaining which segments use them.
 */
class SegmentUsageGuard
{
    public static function protectDelete(DeleteAction $action): DeleteAction
    {
        return $action
            ->disabled(fn (Model $record): bool => SegmentUsage::isUsed($record))
            ->tooltip(function (Model $record): ?string {
                $segments = SegmentUsage::segmentsUsing($record);

                if ($segments->isEmpty()) {
                    return null;
                }

                return 'Used in the conditions of '.SegmentUsage::describeSegments($segments).'. Remove it from those conditions to delete it.';
            });
    }

    /**
     * Deletes the selected records that no segment uses, and reports the ones that were skipped.
     */
    public static function protectBulkDelete(DeleteBulkAction $action): DeleteBulkAction
    {
        return $action->using(function (DeleteBulkAction $action, Collection $records): void {
            $blockingSegmentNames = [];

            $records->each(function (Model $record) use ($action, &$blockingSegmentNames): void {
                $segments = SegmentUsage::segmentsUsing($record);

                if ($segments->isNotEmpty()) {
                    array_push($blockingSegmentNames, ...$segments->pluck('name')->all());

                    $action->reportBulkProcessingFailure(
                        'used_in_segments',
                        message: function (int $failureCount) use (&$blockingSegmentNames): string {
                            $names = collect($blockingSegmentNames)->unique()->sort()->map(fn (string $name): string => "\"{$name}\"")->join(', ', ' and ');

                            return ($failureCount === 1 ? '1 record was kept because it is' : "{$failureCount} records were kept because they are")
                                ." used in the conditions of: {$names}.";
                        },
                    );

                    return;
                }

                $record->delete() || $action->reportBulkProcessingFailure();
            });
        });
    }
}
