<?php

namespace App\Filament\Pages\Tenancy;

use App\Filament\Support\OrganizationCurrencySelect;
use App\Filament\Support\OrganizationNameInput;
use App\Filament\Support\OrganizationTimezoneSelect;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class EditOrganization extends EditTenantProfile
{
    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    public static function getLabel(): string
    {
        return 'Organization Settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                OrganizationNameInput::make(CurrentOrganization::find()),

                Textarea::make('description')
                    ->maxLength(1000)
                    ->rows(3),

                OrganizationTimezoneSelect::make(),
                OrganizationCurrencySelect::make(),

                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->imageEditor()
                    ->directory('organization-logos')
                    ->maxSize(2048)
                    ->nullable(),
            ]);
    }
}
