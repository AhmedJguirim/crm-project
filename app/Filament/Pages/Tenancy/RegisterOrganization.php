<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RegisterOrganization extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'New Organization';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(Organization::class, 'name'),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->rows(3),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->imageEditor()
                    ->directory('organization-logos')
                    ->maxSize(2048)
                    ->nullable(),
            ]);
    }

    protected function handleRegistration(array $data): Organization
    {
        $data['slug'] = Str::slug($data['name']).'-'.Str::random(6);
        $data['created_by'] = auth()->id();
        $data['personal_team'] = false;

        $organization = Organization::create($data);

        $organization->members()->attach(auth()->id(), [
            'role' => OrganizationRole::Owner->value,
        ]);

        return $organization;
    }
}
