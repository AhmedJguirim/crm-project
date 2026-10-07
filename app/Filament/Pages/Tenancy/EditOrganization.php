<?php

namespace App\Filament\Pages\Tenancy;

use App\Filament\Support\OrganizationCurrencySelect;
use App\Filament\Support\OrganizationNameInput;
use App\Filament\Support\OrganizationTimezoneSelect;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class EditOrganization extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Organization Settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                OrganizationNameInput::make(Filament::getTenant()),

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
