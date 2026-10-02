<?php

namespace App\Services\Segments;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentValueInput;
use App\Models\CustomField;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves the fields segment conditions can target within an organization, and checks whether conditions are complete.
 */
class SegmentFieldCatalog
{
    /** The largest "number of days" of a condition, about 100 years: beyond it PostgreSQL fails on the date. */
    public const MAX_DAYS = 36500;

    /** @var Collection<string, CustomField>|null */
    private ?Collection $customFields = null;

    public function __construct(public readonly int $organizationId) {}

    public static function forOrganization(int $organizationId): self
    {
        return new self($organizationId);
    }

    /**
     * Custom field definitions keyed by their immutable key, including soft-deleted ones (their values are kept).
     *
     * @return Collection<string, CustomField>
     */
    public function customFields(): Collection
    {
        return $this->customFields ??= CustomField::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->organizationId)
            ->orderBy('order')
            ->get()
            ->keyBy('key');
    }

    public function customField(?string $key): ?CustomField
    {
        if ($key === null) {
            return null;
        }

        return $this->customFields()->get($key);
    }

    public function fieldKind(SegmentConditionData $condition): ?SegmentFieldKind
    {
        if ($kind = $condition->type->fixedFieldKind()) {
            return $kind;
        }

        return match ($condition->type) {
            SegmentConditionType::Attribute => ContactAttribute::tryFrom((string) $condition->field)?->fieldKind(),
            SegmentConditionType::CustomField => ($field = $this->customField($condition->field))
                ? SegmentFieldKind::fromCustomFieldType($field->type)
                : null,
            default => null,
        };
    }

    public function fieldLabel(SegmentConditionData $condition): ?string
    {
        return match ($condition->type) {
            SegmentConditionType::Attribute => ContactAttribute::tryFrom((string) $condition->field)?->getLabel(),
            SegmentConditionType::CustomField => ($field = $this->customField($condition->field))
                ? $field->name.($field->trashed() ? ' (deleted)' : '')
                : null,
            default => null,
        };
    }

    /**
     * Selectable values of select / multi-select fields, keyed by stored value.
     *
     * @return array<string, string>
     */
    public function fieldOptions(SegmentConditionType $type, ?string $field): array
    {
        return match ($type) {
            SegmentConditionType::Attribute => ContactAttribute::tryFrom((string) $field)?->options() ?? [],
            SegmentConditionType::CustomField => $this->customField($field)?->optionLabels() ?? [],
            default => [],
        };
    }

    /** @param  iterable<SegmentRuleData>  $rules */
    public function areRulesComplete(iterable $rules): bool
    {
        $rules = collect($rules);

        return $rules->isNotEmpty() && $rules->every(fn (SegmentRuleData $rule): bool => $this->isRuleComplete($rule));
    }

    public function isRuleComplete(SegmentRuleData $rule): bool
    {
        return count($rule->conditions) > 0
            && collect($rule->conditions)->every(fn (SegmentConditionData $condition): bool => $this->isConditionComplete($condition));
    }

    public function isConditionComplete(SegmentConditionData $condition): bool
    {
        $kind = $this->fieldKind($condition);

        if (! $kind || ! $kind->supports($condition->operator)) {
            return false;
        }

        return match ($condition->operator->valueInput()) {
            SegmentValueInput::None => true,
            SegmentValueInput::Single => $this->isValidScalar($kind, $condition->value('value')),
            SegmentValueInput::Range => $this->isValidScalar($kind, $condition->value('value'))
                && $this->isValidScalar($kind, $condition->value('value_to')),
            SegmentValueInput::Multiple => count($condition->values()) > 0,
            SegmentValueInput::Days => $this->isIntegerBetween($condition->value('days'), 1, self::MAX_DAYS),
            SegmentValueInput::Month => $this->isIntegerBetween($condition->value('month'), 1, 12),
            SegmentValueInput::DayAndMonth => $this->isIntegerBetween($condition->value('month'), 1, 12)
                && $this->isIntegerBetween($condition->value('day'), 1, 31),
            SegmentValueInput::ActivityCriteria => $condition->operator->requiresDays()
                ? $this->isIntegerBetween($condition->value('days'), 1, self::MAX_DAYS)
                : blank($condition->value('days')) || $this->isIntegerBetween($condition->value('days'), 1, self::MAX_DAYS),
            SegmentValueInput::DealCriteria => blank($condition->value('min_value')) || is_numeric($condition->value('min_value')),
        };
    }

    private function isValidScalar(SegmentFieldKind $kind, mixed $value): bool
    {
        if (blank($value) || is_array($value)) {
            return false;
        }

        return match ($kind) {
            SegmentFieldKind::Number => is_numeric($value),
            SegmentFieldKind::Date => self::parseDate($value) !== null,
            default => true,
        };
    }

    private function isIntegerBetween(mixed $value, int $min, int $max): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false;
    }

    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || ! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
