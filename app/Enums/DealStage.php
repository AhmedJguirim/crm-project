<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum DealStage: string implements HasColor, HasIcon, HasLabel
{
    case Lead = 'lead';
    case Discovery = 'discovery';
    case ProposalSent = 'proposal_sent';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Discovery => 'Discovery',
            self::ProposalSent => 'Proposal Sent',
            self::Negotiating => 'Negotiating',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Lead => 'gray',
            self::Discovery => 'info',
            self::ProposalSent => 'warning',
            self::Negotiating => 'orange',
            self::Won => 'success',
            self::Lost => 'gray',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Lead => Heroicon::OutlinedLightBulb,
            self::Discovery => Heroicon::OutlinedMagnifyingGlass,
            self::ProposalSent => Heroicon::OutlinedDocumentText,
            self::Negotiating => Heroicon::OutlinedHandRaised,
            self::Won => Heroicon::OutlinedCheckBadge,
            self::Lost => Heroicon::OutlinedXCircle,
        };
    }
}
