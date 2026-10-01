<?php

namespace App\Filament\Resources\Segments\Pages;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Filament\Resources\Segments\Actions\PublishSegmentAction;
use App\Filament\Resources\Segments\Actions\SegmentDeletionActions;
use App\Filament\Resources\Segments\Schemas\SegmentConditionForm;
use App\Filament\Resources\Segments\Schemas\SegmentForm;
use App\Filament\Resources\Segments\SegmentResource;
use App\Jobs\SyncSegmentMembership;
use App\Models\Segment;
use App\Services\Segments\SegmentConditionDescriber;
use App\Services\Segments\SegmentFieldCatalog;
use App\Services\Segments\SegmentQueryBuilder;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The segment rule builder: rules (OR) listed on the left, the selected rule's conditions (AND) on the right.
 *
 * While the segment is unpublished, edits apply to its definition directly. Once published, edits are kept in a
 * draft until "Save Changes" publishes them (and recomputes the members) or "Cancel Changes" discards them.
 */
class SegmentRuleEngine extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SegmentResource::class;

    protected string $view = 'filament.resources.segments.pages.segment-rule-engine';

    public ?string $selectedRuleId = null;

    private ?SegmentFieldCatalog $catalog = null;

    private ?SegmentConditionDescriber $describer = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->selectedRuleId = $this->rules()->first()?->id;
    }

    public function getTitle(): string|Htmlable
    {
        return "Edit '{$this->segment()->name}' Segment";
    }

    public function getBreadcrumb(): string
    {
        return 'Rules';
    }

    public function segment(): Segment
    {
        /** @var Segment */
        return $this->getRecord();
    }

    /** @return Collection<int, SegmentRuleData> */
    public function rules(): Collection
    {
        return $this->segment()->workingRules();
    }

    public function selectedRule(): ?SegmentRuleData
    {
        return $this->rules()->firstWhere('id', $this->selectedRuleId);
    }

    public function selectRule(string $ruleId): void
    {
        $this->selectedRuleId = $ruleId;
    }

    public function describe(SegmentConditionData $condition): HtmlString
    {
        $this->describer ??= new SegmentConditionDescriber($this->catalog());

        return $this->describer->describe($condition);
    }

    public function isRuleComplete(SegmentRuleData $rule): bool
    {
        return $this->catalog()->isRuleComplete($rule);
    }

    public function isConditionComplete(SegmentConditionData $condition): bool
    {
        return $this->catalog()->isConditionComplete($condition);
    }

    public function ruleMatchCount(SegmentRuleData $rule): ?int
    {
        if (! $this->isRuleComplete($rule)) {
            return null;
        }

        return $this->queryBuilder()->countConditions($rule->conditions);
    }

    public function segmentMatchCount(): int
    {
        return $this->queryBuilder()->count($this->rules());
    }

    protected function getHeaderActions(): array
    {
        return [
            PublishSegmentAction::make(),

            Action::make('viewSegment')
                ->label('View segment')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->visible(fn (Segment $record): bool => $record->is_published)
                ->url(fn (Segment $record): string => SegmentResource::getUrl('view', ['record' => $record])),

            Action::make('rename')
                ->label('Rename')
                ->icon(Heroicon::OutlinedPencil)
                ->color('gray')
                ->fillForm(fn (Segment $record): array => ['name' => $record->name])
                ->schema([SegmentForm::nameInput()])
                ->action(function (Segment $record, array $data): void {
                    $record->update(['name' => $data['name']]);

                    Notification::make()->title('Segment renamed')->success()->send();
                }),

            SegmentDeletionActions::delete()
                ->successRedirectUrl(SegmentResource::getUrl('index')),
        ];
    }

    public function createRuleAction(): Action
    {
        return Action::make('createRule')
            ->label('Create a Rule')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Create a Rule')
            ->modalWidth(Width::Medium)
            ->schema([$this->ruleNameInput()])
            ->action(function (array $data): void {
                $rule = SegmentRuleData::make($data['name']);

                $this->updateRules(fn (Collection $rules): Collection => $rules->push($rule));

                $this->selectedRuleId = $rule->id;
            });
    }

    public function renameRuleAction(): Action
    {
        return Action::make('renameRule')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencil)
            ->color('gray')
            ->size('sm')
            ->modalHeading('Rename Rule')
            ->modalWidth(Width::Medium)
            ->fillForm(fn (array $arguments): array => ['name' => $this->findRule($arguments['rule'] ?? null)?->name])
            ->schema(fn (array $arguments): array => [$this->ruleNameInput($arguments['rule'] ?? null)])
            ->action(fn (array $arguments, array $data) => $this->updateRule(
                $arguments['rule'] ?? null,
                function (SegmentRuleData $rule) use ($data): void {
                    $rule->name = $data['name'];
                },
            ));
    }

    public function deleteRuleAction(): Action
    {
        return Action::make('deleteRule')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete rule '{$this->findRule($arguments['rule'] ?? null)?->name}'?")
            ->modalDescription('The rule and all of its conditions will be removed from the segment.')
            ->action(function (array $arguments): void {
                $this->updateRules(fn (Collection $rules): Collection => $rules
                    ->reject(fn (SegmentRuleData $rule): bool => $rule->id === ($arguments['rule'] ?? null))
                    ->values());

                $this->selectedRuleId = $this->rules()->first()?->id;
            });
    }

    public function addConditionAction(): Action
    {
        return Action::make('addCondition')
            ->label('Add Condition')
            ->icon(Heroicon::OutlinedPlus)
            ->link()
            ->color('gray')
            ->modalHeading('Add Condition to Rule')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Add condition')
            ->schema(fn (): array => SegmentConditionForm::components($this->catalog()))
            ->action(fn (array $arguments, array $data) => $this->updateRule(
                $arguments['rule'] ?? null,
                function (SegmentRuleData $rule) use ($data): void {
                    $condition = SegmentConditionForm::toCondition($data, $this->catalog());

                    if ($condition) {
                        $rule->conditions[] = $condition;
                    }
                },
            ));
    }

    public function editConditionAction(): Action
    {
        return Action::make('editCondition')
            ->label('Edit condition')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->color('gray')
            ->modalHeading('Edit Condition')
            ->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(function (array $arguments): array {
                $condition = $this->findRule($arguments['rule'] ?? null)?->findCondition($arguments['condition'] ?? '');

                return $condition ? SegmentConditionForm::fill($condition, $this->catalog()) : [];
            })
            ->schema(fn (): array => SegmentConditionForm::components($this->catalog()))
            ->action(fn (array $arguments, array $data) => $this->updateRule(
                $arguments['rule'] ?? null,
                function (SegmentRuleData $rule) use ($arguments, $data): void {
                    $rule->conditions = collect($rule->conditions)
                        ->map(fn (SegmentConditionData $condition): SegmentConditionData => $condition->id === ($arguments['condition'] ?? null)
                            ? (SegmentConditionForm::toCondition($data, $this->catalog(), $condition->id) ?? $condition)
                            : $condition)
                        ->all();
                },
            ));
    }

    public function deleteConditionAction(): Action
    {
        return Action::make('deleteCondition')
            ->label('Delete condition')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete condition?')
            ->action(fn (array $arguments) => $this->updateRule(
                $arguments['rule'] ?? null,
                function (SegmentRuleData $rule) use ($arguments): void {
                    $rule->conditions = collect($rule->conditions)
                        ->reject(fn (SegmentConditionData $condition): bool => $condition->id === ($arguments['condition'] ?? null))
                        ->values()
                        ->all();
                },
            ));
    }

    public function saveChangesAction(): Action
    {
        return Action::make('saveChanges')
            ->label('Save Changes')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The segment members will be recomputed with the new rules.')
            ->visible(fn (): bool => $this->segment()->is_published)
            ->disabled(fn (): bool => ! $this->segment()->hasPendingChanges() || ! $this->segment()->canBePublished())
            ->action(function (Action $action): void {
                $segment = $this->segment();
                $segment->publishWorkingRules();

                SyncSegmentMembership::dispatch($segment->id, auth()->id());

                Notification::make()
                    ->title('Changes saved')
                    ->body('The segment members are being recomputed. You will be notified when they are ready.')
                    ->success()
                    ->send();

                $action->redirect(SegmentResource::getUrl('view', ['record' => $segment]));
            });
    }

    public function cancelChangesAction(): Action
    {
        return Action::make('cancelChanges')
            ->label('Cancel Changes')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('All changes made since the segment was last published will be lost.')
            ->visible(fn (): bool => $this->segment()->hasPendingChanges())
            ->action(function (): void {
                $this->segment()->discardDraft();

                if (! $this->selectedRule()) {
                    $this->selectedRuleId = $this->rules()->first()?->id;
                }

                Notification::make()->title('Changes discarded')->success()->send();
            });
    }

    private function ruleNameInput(?string $ignoreRuleId = null): TextInput
    {
        return TextInput::make('name')
            ->label('Rule Name')
            ->required()
            ->maxLength(255)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($ignoreRuleId): void {
                $isTaken = $this->rules()->contains(fn (SegmentRuleData $rule): bool => $rule->id !== $ignoreRuleId
                    && Str::lower(trim($rule->name)) === Str::lower(trim((string) $value)));

                if ($isTaken) {
                    $fail('A rule with this name already exists in this segment.');
                }
            });
    }

    private function findRule(?string $ruleId): ?SegmentRuleData
    {
        return $this->rules()->firstWhere('id', $ruleId);
    }

    /**
     * @param  Closure(SegmentRuleData): void  $callback
     */
    private function updateRule(?string $ruleId, Closure $callback): void
    {
        $this->updateRules(fn (Collection $rules): Collection => $rules->each(function (SegmentRuleData $rule) use ($ruleId, $callback): void {
            if ($rule->id === $ruleId) {
                $callback($rule);
            }
        }));
    }

    /**
     * @param  Closure(Collection<int, SegmentRuleData>): Collection<int, SegmentRuleData>  $callback
     */
    private function updateRules(Closure $callback): void
    {
        $this->segment()->storeWorkingRules($callback($this->rules()));
    }

    private function catalog(): SegmentFieldCatalog
    {
        return $this->catalog ??= $this->segment()->fieldCatalog();
    }

    private function queryBuilder(): SegmentQueryBuilder
    {
        return new SegmentQueryBuilder($this->catalog());
    }
}
