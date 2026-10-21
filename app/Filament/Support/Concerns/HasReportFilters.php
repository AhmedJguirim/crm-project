<?php

namespace App\Filament\Support\Concerns;

use App\Enums\ReportPeriodPreset;
use App\Services\Owners\OwnerOptions;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The filter bar (period and owner) of the pages that host report widgets. The widgets read the values through
 * `App\Support\Reports\DashboardFilters`, which validates them.
 */
trait HasReportFilters
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Period')
                ->options(ReportPeriodPreset::class)
                ->default(ReportPeriodPreset::ThisMonth)
                ->selectablePlaceholder(false),
            DatePicker::make('from')
                ->label('From')
                ->visible(fn (Get $get): bool => self::isCustom($get('period'))),
            DatePicker::make('to')
                ->label('To')
                ->visible(fn (Get $get): bool => self::isCustom($get('period'))),
            Select::make('owner')
                ->label('Owner')
                ->placeholder('Everyone')
                ->options(fn (): array => ['me' => 'Me'] + OwnerOptions::forForm(null))
                ->visible(fn (): bool => (CurrentOrganization::find()?->members()->count() ?? 0) > 1),
        ]);
    }

    /**
     * Filters are remembered per organization: an owner picked in one organization must not come back in another.
     */
    public function getFiltersSessionKey(): string
    {
        return md5(static::class).'_filters_'.CurrentOrganization::find()?->getKey();
    }

    private static function isCustom(mixed $value): bool
    {
        return $value === ReportPeriodPreset::Custom || $value === 'custom';
    }
}
