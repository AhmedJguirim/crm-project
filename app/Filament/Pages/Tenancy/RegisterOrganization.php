<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\OrganizationRole;
use App\Filament\Support\OrganizationTimezoneSelect;
use App\Models\Organization;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
                OrganizationTimezoneSelect::make(),
                Hidden::make('timezone_from_browser')
                    ->dehydrated(false)
                    ->extraAttributes(['x-init' => 'setTimeout(() => $wire.prefillTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone), 1000)']),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->imageEditor()
                    ->directory('organization-logos')
                    ->maxSize(2048)
                    ->nullable(),
            ]);
    }

    /**
     * Called by the browser once the form is shown: the timezone of the browser becomes the default, unless the user
     * already chose one. A value that is not a known identifier is ignored.
     *
     * The browser calls it one second after the page is shown: the searchable select makes its own call to the
     * component when it loads, and a call made at the same time loses its change to that one's response.
     */
    public function prefillTimezone(?string $timezone): void
    {
        if (! in_array($timezone, Organization::timezoneIdentifiers(), true)) {
            return;
        }

        if (in_array($this->data['timezone'] ?? null, [null, '', 'UTC'], true)) {
            $this->data['timezone'] = $timezone;
        }
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
