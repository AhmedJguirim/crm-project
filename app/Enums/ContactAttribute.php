<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasLabel;

/**
 * Built-in contact columns that can be used in segment conditions.
 */
enum ContactAttribute: string implements HasLabel
{
    case Name = 'name';
    case Email = 'email';
    case Phone = 'phone';
    case Status = 'status';
    case LeadSource = 'lead_source';
    case CreatedAt = 'created_at';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Name => 'Name',
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Status => 'Status',
            self::LeadSource => 'Lead Source',
            self::CreatedAt => 'Created Date',
        };
    }

    public function fieldKind(): SegmentFieldKind
    {
        return match ($this) {
            self::Name, self::Phone => SegmentFieldKind::Text,
            self::Email => SegmentFieldKind::Email,
            self::Status, self::LeadSource => SegmentFieldKind::Select,
            self::CreatedAt => SegmentFieldKind::Date,
        };
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $enum = match ($this) {
            self::Status => ContactStatus::class,
            self::LeadSource => LeadSource::class,
            default => null,
        };

        if ($enum === null) {
            return [];
        }

        return collect($enum::cases())
            ->mapWithKeys(fn (HasLabel&BackedEnum $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
