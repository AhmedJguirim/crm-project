<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The fixed list of industries a company can be in. Not editable per organization: an organization that needs its own
 * list can add a custom select field.
 */
enum CompanyIndustry: string implements HasColor, HasIcon, HasLabel
{
    case Software = 'software';
    case CloudHosting = 'cloud_hosting';
    case Cybersecurity = 'cybersecurity';
    case Consulting = 'consulting';
    case FinanceBanking = 'finance_banking';
    case Insurance = 'insurance';
    case Healthcare = 'healthcare';
    case PharmaBiotech = 'pharma_biotech';
    case Education = 'education';
    case GovernmentPublicSector = 'government_public_sector';
    case RetailEcommerce = 'retail_ecommerce';
    case Manufacturing = 'manufacturing';
    case EnergyUtilities = 'energy_utilities';
    case Telecommunications = 'telecommunications';
    case MediaEntertainment = 'media_entertainment';
    case MarketingAdvertising = 'marketing_advertising';
    case TransportLogistics = 'transport_logistics';
    case RealEstateConstruction = 'real_estate_construction';
    case HospitalityTravel = 'hospitality_travel';
    case NonProfit = 'non_profit';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Software => 'Software',
            self::CloudHosting => 'Cloud & Hosting',
            self::Cybersecurity => 'Cybersecurity',
            self::Consulting => 'Consulting',
            self::FinanceBanking => 'Finance & Banking',
            self::Insurance => 'Insurance',
            self::Healthcare => 'Healthcare',
            self::PharmaBiotech => 'Pharma & Biotech',
            self::Education => 'Education',
            self::GovernmentPublicSector => 'Government & Public Sector',
            self::RetailEcommerce => 'Retail & E-commerce',
            self::Manufacturing => 'Manufacturing',
            self::EnergyUtilities => 'Energy & Utilities',
            self::Telecommunications => 'Telecommunications',
            self::MediaEntertainment => 'Media & Entertainment',
            self::MarketingAdvertising => 'Marketing & Advertising',
            self::TransportLogistics => 'Transport & Logistics',
            self::RealEstateConstruction => 'Real Estate & Construction',
            self::HospitalityTravel => 'Hospitality & Travel',
            self::NonProfit => 'Non-profit',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return 'gray';
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return null;
    }
}
