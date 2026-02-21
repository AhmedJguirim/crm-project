<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DealStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'gray',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }
}
