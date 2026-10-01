<?php

namespace App\Services\Segments;

use App\Data\Segments\SegmentConditionData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use App\Enums\SegmentValueInput;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Tag;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Turns a segment condition into a readable sentence, e.g. "If the contact Email is from domain acme.com".
 */
class SegmentConditionDescriber
{
    /** @var array<string, array<int|string, string>> */
    private array $namesCache = [];

    public function __construct(private readonly SegmentFieldCatalog $catalog) {}

    public function describe(SegmentConditionData $condition): HtmlString
    {
        $parts = ['If the contact'];

        if ($condition->type->requiresField()) {
            $parts[] = $this->highlight($this->catalog->fieldLabel($condition) ?? 'Unknown field (deleted)');
        }

        $parts[] = e($condition->operator->getLabel());
        $parts[] = $this->describeValue($condition);

        return new HtmlString(implode(' ', array_filter($parts)));
    }

    private function describeValue(SegmentConditionData $condition): ?string
    {
        $kind = $this->catalog->fieldKind($condition);

        return match ($condition->operator->valueInput()) {
            SegmentValueInput::None => null,
            SegmentValueInput::Single => $this->highlight($this->labelForSingleValue($condition, $kind)),
            SegmentValueInput::Range => $this->highlight((string) $condition->value('value')).' and '.$this->highlight((string) $condition->value('value_to')),
            SegmentValueInput::Multiple => $this->highlightList($this->labelsForValues($condition)),
            SegmentValueInput::Days => $this->highlight((string) $condition->value('days')).' days'.($condition->operator === SegmentOperator::MoreThanDaysAgo ? ' ago' : ''),
            SegmentValueInput::Month => $this->highlight($this->monthName($condition->value('month'))),
            SegmentValueInput::DayAndMonth => $this->highlight($condition->value('day').' '.$this->monthName($condition->value('month'))),
            SegmentValueInput::ActivityCriteria => $this->describeActivityCriteria($condition),
            SegmentValueInput::DealCriteria => $this->describeDealCriteria($condition),
        };
    }

    private function labelForSingleValue(SegmentConditionData $condition, ?SegmentFieldKind $kind): string
    {
        $value = (string) $condition->value('value');

        if ($kind !== SegmentFieldKind::Select) {
            return $value;
        }

        return $this->catalog->fieldOptions($condition->type, $condition->field)[$value] ?? $value;
    }

    /** @return array<int, string> */
    private function labelsForValues(SegmentConditionData $condition): array
    {
        $names = match (true) {
            $condition->type === SegmentConditionType::Tags => $this->names(Tag::class),
            $condition->operator === SegmentOperator::CompanyTypeIsAnyOf => $this->names(CompanyType::class),
            $condition->type === SegmentConditionType::Company => $this->names(Company::class),
            default => $this->catalog->fieldOptions($condition->type, $condition->field),
        };

        return collect($condition->values())
            ->map(fn (mixed $value): string => $names[$value] ?? "{$value} (deleted)")
            ->all();
    }

    private function describeActivityCriteria(SegmentConditionData $condition): string
    {
        $parts = [];
        $types = collect($condition->values('activity_types'))
            ->map(fn (string $type): string => ActivityType::tryFrom($type)?->getLabel() ?? $type)
            ->all();

        if ($types !== []) {
            $parts[] = 'of type '.$this->highlightList($types);
        }

        if ($outcome = ActivityOutcome::tryFrom((string) $condition->value('outcome'))) {
            $parts[] = 'with outcome '.$this->highlight($outcome->getLabel());
        }

        if ($condition->operator === SegmentOperator::LastActivityMoreThanDaysAgo) {
            $parts[] = $this->highlight((string) $condition->value('days')).' days ago';
        } elseif (filled($condition->value('days'))) {
            $parts[] = 'in the last '.$this->highlight((string) $condition->value('days')).' days';
        } else {
            $parts[] = 'ever';
        }

        return implode(' ', $parts);
    }

    private function describeDealCriteria(SegmentConditionData $condition): ?string
    {
        $parts = [];
        $statuses = collect($condition->values('deal_statuses'))
            ->map(fn (string $status): string => DealStatus::tryFrom($status)?->getLabel() ?? $status)
            ->all();
        $stages = collect($condition->values('deal_stages'))
            ->map(fn (string $stage): string => DealStage::tryFrom($stage)?->getLabel() ?? $stage)
            ->all();

        if ($statuses !== []) {
            $parts[] = 'with status '.$this->highlightList($statuses);
        }

        if ($stages !== []) {
            $parts[] = 'in stage '.$this->highlightList($stages);
        }

        if (is_numeric($condition->value('min_value'))) {
            $parts[] = 'worth at least '.$this->highlight(Number::format((float) $condition->value('min_value')));
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  class-string<Tag|Company|CompanyType>  $model
     * @return array<int|string, string>
     */
    private function names(string $model): array
    {
        return $this->namesCache[$model] ??= $model::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->catalog->organizationId)
            ->pluck('name', 'id')
            ->all();
    }

    private function monthName(mixed $month): string
    {
        return now()->startOfYear()->month((int) $month)->format('F');
    }

    /** @param  array<int, string>  $values */
    private function highlightList(array $values): string
    {
        return collect($values)->map(fn (string $value): string => $this->highlight($value))->join(', ');
    }

    private function highlight(string $value): string
    {
        return '<span class="font-semibold text-primary-600 dark:text-primary-400">'.e($value).'</span>';
    }
}
