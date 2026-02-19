<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum ActivityType: string implements HasColor, HasIcon, HasLabel
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case WhatsApp = 'whatsapp';
    case Message = 'message';
    case Note = 'note';
    case InPerson = 'in_person';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::WhatsApp => 'WhatsApp',
            self::Message => 'Message',
            self::Note => 'Note',
            self::InPerson => 'In-Person',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Call => 'info',
            self::Email => 'primary',
            self::Meeting => 'warning',
            self::WhatsApp => 'success',
            self::Message => 'info',
            self::Note => 'gray',
            self::InPerson => 'danger',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Call => Heroicon::OutlinedPhone,
            self::Email => Heroicon::OutlinedEnvelope,
            self::Meeting => Heroicon::OutlinedUserGroup,
            self::WhatsApp => Heroicon::OutlinedChatBubbleLeftRight,
            self::Message => Heroicon::OutlinedChatBubbleBottomCenterText,
            self::Note => Heroicon::OutlinedDocumentText,
            self::InPerson => Heroicon::OutlinedMapPin,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }

    public function hasDuration(): bool
    {
        return in_array($this, [self::Call, self::Meeting, self::InPerson]);
    }
}
