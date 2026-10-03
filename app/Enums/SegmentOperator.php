<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SegmentOperator: string implements HasLabel
{
    case Is = 'is';
    case IsNot = 'is_not';
    case Contains = 'contains';
    case DoesNotContain = 'does_not_contain';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case IsBlank = 'is_blank';
    case IsNotBlank = 'is_not_blank';
    case IsFromDomain = 'is_from_domain';
    case IsNotFromDomain = 'is_not_from_domain';
    case EqualTo = 'equal_to';
    case NotEqualTo = 'not_equal_to';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case GreaterThanOrEqualTo = 'greater_than_or_equal_to';
    case LessThanOrEqualTo = 'less_than_or_equal_to';
    case Before = 'before';
    case After = 'after';
    case On = 'on';
    case OnOrBefore = 'on_or_before';
    case OnOrAfter = 'on_or_after';
    case Between = 'between';
    case WithinLastDays = 'within_last_days';
    case MoreThanDaysAgo = 'more_than_days_ago';
    case MonthIs = 'month_is';
    case DayAndMonthIs = 'day_and_month_is';
    case IsAnyOf = 'is_any_of';
    case IsNoneOf = 'is_none_of';
    case ContainsAnyOf = 'contains_any_of';
    case ContainsAllOf = 'contains_all_of';
    case ContainsNoneOf = 'contains_none_of';
    case DoesNotContainAllOf = 'does_not_contain_all_of';
    case HasAnyOf = 'has_any_of';
    case HasAllOf = 'has_all_of';
    case HasNoneOf = 'has_none_of';
    case HasOnly = 'has_only';
    case HasNoTags = 'has_no_tags';
    case HasAnyTags = 'has_any_tags';
    case BelongsToAnyOf = 'belongs_to_any_of';
    case BelongsToNoneOf = 'belongs_to_none_of';
    case CompanyTypeIsAnyOf = 'company_type_is_any_of';
    case HasNoCompany = 'has_no_company';
    case HasAnyCompany = 'has_any_company';
    case HasHadActivity = 'has_had_activity';
    case HasNotHadActivity = 'has_not_had_activity';
    case LastActivityMoreThanDaysAgo = 'last_activity_more_than_days_ago';
    case HasDeal = 'has_deal';
    case HasNoDeal = 'has_no_deal';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Is => 'is',
            self::IsNot => 'is not',
            self::Contains => 'contains',
            self::DoesNotContain => 'does not contain',
            self::StartsWith => 'starts with',
            self::EndsWith => 'ends with',
            self::IsBlank => 'is blank',
            self::IsNotBlank => 'is not blank',
            self::IsFromDomain => 'is from domain',
            self::IsNotFromDomain => 'is not from domain',
            self::EqualTo => 'is equal to',
            self::NotEqualTo => 'is not equal to',
            self::GreaterThan => 'is greater than',
            self::LessThan => 'is less than',
            self::GreaterThanOrEqualTo => 'is greater than or equal to',
            self::LessThanOrEqualTo => 'is less than or equal to',
            self::Before => 'is before',
            self::After => 'is after',
            self::On => 'is on',
            self::OnOrBefore => 'is on or before',
            self::OnOrAfter => 'is on or after',
            self::Between => 'is between',
            self::WithinLastDays => 'is within the last',
            self::MoreThanDaysAgo => 'is more than',
            self::MonthIs => 'month is',
            self::DayAndMonthIs => 'day and month is',
            self::IsAnyOf => 'is any of',
            self::IsNoneOf => 'is none of',
            self::ContainsAnyOf => 'contains any of',
            self::ContainsAllOf => 'contains all of',
            self::ContainsNoneOf => 'contains none of',
            self::DoesNotContainAllOf => 'does not contain all of',
            self::HasAnyOf => 'has any of',
            self::HasAllOf => 'has all of',
            self::HasNoneOf => 'has none of',
            self::HasOnly => 'has only',
            self::HasNoTags => 'has no tags',
            self::HasAnyTags => 'has any tags',
            self::BelongsToAnyOf => 'belongs to any of',
            self::BelongsToNoneOf => 'belongs to none of',
            self::CompanyTypeIsAnyOf => 'belongs to a company of type',
            self::HasNoCompany => 'has no company',
            self::HasAnyCompany => 'has any company',
            self::HasHadActivity => 'has had an activity',
            self::HasNotHadActivity => 'has not had an activity',
            self::LastActivityMoreThanDaysAgo => 'last had an activity more than',
            self::HasDeal => 'has a deal',
            self::HasNoDeal => 'has no deal',
        };
    }

    public function valueInput(): SegmentValueInput
    {
        return match ($this) {
            self::IsBlank,
            self::IsNotBlank,
            self::HasNoTags,
            self::HasAnyTags,
            self::HasNoCompany,
            self::HasAnyCompany => SegmentValueInput::None,

            self::Between => SegmentValueInput::Range,

            self::IsAnyOf,
            self::IsNoneOf,
            self::ContainsAnyOf,
            self::ContainsAllOf,
            self::ContainsNoneOf,
            self::DoesNotContainAllOf,
            self::HasAnyOf,
            self::HasAllOf,
            self::HasNoneOf,
            self::HasOnly,
            self::BelongsToAnyOf,
            self::BelongsToNoneOf,
            self::CompanyTypeIsAnyOf => SegmentValueInput::Multiple,

            self::WithinLastDays,
            self::MoreThanDaysAgo => SegmentValueInput::Days,

            self::MonthIs => SegmentValueInput::Month,
            self::DayAndMonthIs => SegmentValueInput::DayAndMonth,

            self::HasHadActivity,
            self::HasNotHadActivity,
            self::LastActivityMoreThanDaysAgo => SegmentValueInput::ActivityCriteria,

            self::HasDeal,
            self::HasNoDeal => SegmentValueInput::DealCriteria,

            default => SegmentValueInput::Single,
        };
    }

    /** Whether an activity condition using this operator must specify a number of days. */
    public function requiresDays(): bool
    {
        return in_array($this, [self::WithinLastDays, self::MoreThanDaysAgo, self::LastActivityMoreThanDaysAgo], true);
    }

    /**
     * Whether contacts with no value for the field also match: the negative operators on a field value. The
     * relationship operators (has none of, has no deal, …) are left out, their wording already says it.
     */
    public function includesBlankValues(): bool
    {
        return in_array($this, [
            self::IsNot,
            self::DoesNotContain,
            self::IsNotFromDomain,
            self::NotEqualTo,
            self::IsNoneOf,
            self::ContainsNoneOf,
            self::DoesNotContainAllOf,
        ], true);
    }
}
