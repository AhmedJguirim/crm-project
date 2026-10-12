<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExportFormat: string implements HasLabel
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Xlsx => 'Excel (.xlsx)',
            self::Csv => 'CSV',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
        };
    }
}
