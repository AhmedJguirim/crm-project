<?php

namespace App\Data\Segments;

use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use App\Enums\SegmentRefreshFrequency;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class SegmentConditionData extends Data
{
    /**
     * @param  array<string, mixed>  $value  Keys used depend on the operator's value input: `value`, `value_to`, `values`,
     *                                       `days`, `month`, `day`, `activity_types`, `outcome`, `deal_statuses`, `deal_stages`, `min_value`.
     */
    public function __construct(
        public string $id,
        public SegmentConditionType $type,
        public ?string $field,
        public SegmentOperator $operator,
        public array $value = [],
    ) {}

    /** @param  array<string, mixed>  $value */
    public static function make(SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): self
    {
        return new self((string) Str::ulid(), $type, $field, $operator, $value);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->value[$key] ?? $default;
    }

    /** @return array<int, mixed> */
    public function values(string $key = 'values'): array
    {
        return array_values(array_filter(
            (array) ($this->value[$key] ?? []),
            fn (mixed $item): bool => filled($item),
        ));
    }

    /**
     * Whether the matches can change with the organization's timezone: the conditions relative to today, and every
     * condition on a timestamp attribute (the Created Date), which is compared as a date in that timezone. Dates of
     * custom fields are plain dates, so only their relative conditions count.
     */
    public function dependsOnTimezone(): bool
    {
        if ($this->refreshFrequency() !== null) {
            return true;
        }

        if ($this->type !== SegmentConditionType::Attribute) {
            return false;
        }

        return ContactAttribute::tryFrom((string) $this->field)?->fieldKind() === SegmentFieldKind::Date;
    }

    /**
     * How often the membership must be recomputed on a schedule because the condition depends on the current time,
     * or null when it only changes when data is written.
     */
    public function refreshFrequency(): ?SegmentRefreshFrequency
    {
        return match (true) {
            in_array($this->operator, [SegmentOperator::WithinLastDays, SegmentOperator::MoreThanDaysAgo], true) => SegmentRefreshFrequency::Daily,
            $this->type !== SegmentConditionType::Activity => null,
            $this->operator === SegmentOperator::LastActivityMoreThanDaysAgo => SegmentRefreshFrequency::Hourly,
            in_array($this->operator, [SegmentOperator::HasHadActivity, SegmentOperator::HasNotHadActivity], true)
                && filled($this->value('days')) => SegmentRefreshFrequency::Hourly,
            default => null,
        };
    }
}
