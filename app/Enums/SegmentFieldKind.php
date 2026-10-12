<?php

namespace App\Enums;

/**
 * The kind of data a segment condition targets, which determines the available operators and value inputs.
 */
enum SegmentFieldKind: string
{
    case Text = 'text';
    case Email = 'email';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Tags = 'tags';
    case Company = 'company';
    case Activity = 'activity';
    case Deal = 'deal';

    public static function fromCustomFieldType(string $type): self
    {
        return match ($type) {
            'email' => self::Email,
            'number' => self::Number,
            'date' => self::Date,
            'select' => self::Select,
            'multiselect' => self::MultiSelect,
            default => self::Text,
        };
    }

    /** @return array<int, SegmentOperator> */
    public function operators(): array
    {
        $textOperators = [
            SegmentOperator::Is,
            SegmentOperator::IsNot,
            SegmentOperator::Contains,
            SegmentOperator::DoesNotContain,
            SegmentOperator::StartsWith,
            SegmentOperator::EndsWith,
            SegmentOperator::IsBlank,
            SegmentOperator::IsNotBlank,
        ];

        return match ($this) {
            self::Text => $textOperators,
            self::Email => [
                SegmentOperator::IsFromDomain,
                SegmentOperator::IsNotFromDomain,
                ...$textOperators,
            ],
            self::Number => [
                SegmentOperator::EqualTo,
                SegmentOperator::NotEqualTo,
                SegmentOperator::GreaterThan,
                SegmentOperator::LessThan,
                SegmentOperator::GreaterThanOrEqualTo,
                SegmentOperator::LessThanOrEqualTo,
                SegmentOperator::IsBlank,
                SegmentOperator::IsNotBlank,
            ],
            self::Date => [
                SegmentOperator::Before,
                SegmentOperator::After,
                SegmentOperator::On,
                SegmentOperator::OnOrBefore,
                SegmentOperator::OnOrAfter,
                SegmentOperator::Between,
                SegmentOperator::WithinLastDays,
                SegmentOperator::MoreThanDaysAgo,
                SegmentOperator::MonthIs,
                SegmentOperator::DayAndMonthIs,
                SegmentOperator::IsBlank,
                SegmentOperator::IsNotBlank,
            ],
            self::Select => [
                SegmentOperator::Is,
                SegmentOperator::IsNot,
                SegmentOperator::IsAnyOf,
                SegmentOperator::IsNoneOf,
                SegmentOperator::IsBlank,
                SegmentOperator::IsNotBlank,
            ],
            self::MultiSelect => [
                SegmentOperator::ContainsAnyOf,
                SegmentOperator::ContainsAllOf,
                SegmentOperator::ContainsNoneOf,
                SegmentOperator::DoesNotContainAllOf,
                SegmentOperator::IsBlank,
                SegmentOperator::IsNotBlank,
            ],
            self::Tags => [
                SegmentOperator::HasAnyOf,
                SegmentOperator::HasAllOf,
                SegmentOperator::HasNoneOf,
                SegmentOperator::HasOnly,
                SegmentOperator::HasNoTags,
                SegmentOperator::HasAnyTags,
            ],
            self::Company => [
                SegmentOperator::BelongsToAnyOf,
                SegmentOperator::BelongsToNoneOf,
                SegmentOperator::CompanyTypeIsAnyOf,
                SegmentOperator::HasNoCompany,
                SegmentOperator::HasAnyCompany,
            ],
            self::Activity => [
                SegmentOperator::HasHadActivity,
                SegmentOperator::HasNotHadActivity,
                SegmentOperator::LastActivityMoreThanDaysAgo,
            ],
            self::Deal => [
                SegmentOperator::HasDeal,
                SegmentOperator::HasNoDeal,
            ],
        };
    }

    public function supports(SegmentOperator $operator): bool
    {
        return in_array($operator, $this->operators(), true);
    }
}
