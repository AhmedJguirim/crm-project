<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum SegmentConditionType: string implements HasColor, HasIcon, HasLabel
{
    case Attribute = 'attribute';
    case CustomField = 'custom_field';
    case Tags = 'tags';
    case Company = 'company';
    case Activity = 'activity';
    case Deal = 'deal';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Attribute => 'Contact Attribute',
            self::CustomField => 'Custom Field',
            self::Tags => 'Tags',
            self::Company => 'Companies',
            self::Activity => 'Activities',
            self::Deal => 'Deals',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Attribute => 'primary',
            self::CustomField => 'info',
            self::Tags => 'warning',
            self::Company => 'gray',
            self::Activity => 'success',
            self::Deal => 'danger',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Attribute => Heroicon::OutlinedUser,
            self::CustomField => Heroicon::OutlinedAdjustmentsHorizontal,
            self::Tags => Heroicon::OutlinedTag,
            self::Company => Heroicon::OutlinedBuildingOffice,
            self::Activity => Heroicon::OutlinedChatBubbleLeftRight,
            self::Deal => Heroicon::OutlinedCurrencyDollar,
        };
    }

    /** Whether conditions of this type target a specific field (picked in the "Field" select). */
    public function requiresField(): bool
    {
        return in_array($this, [self::Attribute, self::CustomField], true);
    }

    /** The field kind of conditions that don't target a specific field. */
    public function fixedFieldKind(): ?SegmentFieldKind
    {
        return match ($this) {
            self::Tags => SegmentFieldKind::Tags,
            self::Company => SegmentFieldKind::Company,
            self::Activity => SegmentFieldKind::Activity,
            self::Deal => SegmentFieldKind::Deal,
            default => null,
        };
    }
}
