<?php

namespace App\Enums;

enum DiskAlertLevel: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }
}
