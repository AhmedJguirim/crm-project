<?php

namespace App\Filament\Resources\PipelineStages\Actions;

use App\Enums\DealStatus;
use App\Exceptions\PipelineStageCannotBeDeleted;
use App\Exceptions\UsedInSegmentsException;
use App\Filament\Support\RecordKeyField;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use App\Services\Segments\SegmentUsageIndex;
use App\Support\NotificationText;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Deletes an open stage of the pipeline. A stage with deals asks for the open stage that receives them; Won and Lost
 * have no delete action, and the last open stage and the stages used by segment conditions are disabled.
 */
class PipelineStageDeletionAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize('delete')
            ->visible(fn (PipelineStage $record): bool => $record->type === DealStatus::Open)
            ->disabled(fn (PipelineStage $record): bool => self::isLastOpenStage($record) || app(SegmentUsageIndex::class)->isUsed($record))
            ->tooltip(function (PipelineStage $record): ?string {
                if (self::isLastOpenStage($record)) {
                    return 'A pipeline needs at least one open stage.';
                }

                $segmentNames = app(SegmentUsageIndex::class)->segmentNamesUsing($record);

                if ($segmentNames === []) {
                    return null;
                }

                return 'Used in the conditions of '.SegmentUsageIndex::describe($segmentNames).'. Remove it from those conditions to delete it.';
            })
            ->modalHeading(fn (PipelineStage $record): string => "Delete the stage \"{$record->name}\"?")
            ->modalDescription(function (PipelineStage $record): string {
                $deals = self::dealCount($record);

                return match (true) {
                    $deals === 0 => 'No deal is in this stage. This cannot be undone.',
                    $deals === 1 => 'Its 1 deal moves to the stage you choose. This cannot be undone.',
                    default => "Its {$deals} deals move to the stage you choose. This cannot be undone.",
                };
            })
            ->modalSubmitActionLabel('Delete stage')
            ->fillForm(fn (PipelineStage $record): array => ['target_stage_id' => self::defaultTargetId($record)])
            ->schema(fn (PipelineStage $record): array => [
                RecordKeyField::select('target_stage_id')
                    ->label('Move the deals to')
                    ->options(fn (): array => self::targets($record)->pluck('name', 'id')->all())
                    ->required()
                    ->visible(fn (): bool => self::dealCount($record) > 0),
            ])
            ->using(function (PipelineStage $record, array $data, DeleteAction $action): bool {
                $target = self::targets($record)->firstWhere('id', $data['target_stage_id'] ?? null);

                try {
                    $moved = PipelineStageOrder::deleteOpenStage(DefaultPipeline::ensureFor(CurrentOrganization::get()), $record, $data['target_stage_id'] ?? null);
                } catch (PipelineStageCannotBeDeleted|UsedInSegmentsException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(NotificationText::plain($exception->getMessage()))
                        ->send();

                    $action->cancel();

                    return false;
                }

                $action->successNotificationTitle(NotificationText::plain(
                    $moved === 0
                        ? 'Stage deleted'
                        : "Stage deleted. {$moved} ".Str::plural('deal', $moved)." moved to \"{$target?->name}\"."
                ));

                return true;
            });
    }

    private static function dealCount(PipelineStage $stage): int
    {
        return Deal::forOrganization($stage->organization_id)->where('pipeline_stage_id', $stage->getKey())->count();
    }

    private static function isLastOpenStage(PipelineStage $stage): bool
    {
        return $stage->type === DealStatus::Open && self::targets($stage)->isEmpty();
    }

    /**
     * The other open stages of the same pipeline, in board order.
     *
     * @return Collection<int, PipelineStage>
     */
    private static function targets(PipelineStage $stage): Collection
    {
        return PipelineStage::forOrganization($stage->organization_id)
            ->where('pipeline_id', $stage->pipeline_id)
            ->where('type', DealStatus::Open)
            ->whereKeyNot($stage->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'name', 'position']);
    }

    private static function defaultTargetId(PipelineStage $stage): ?int
    {
        $targets = self::targets($stage);

        return ($targets->last(fn (PipelineStage $other): bool => $other->position < $stage->position) ?? $targets->first())?->getKey();
    }
}
