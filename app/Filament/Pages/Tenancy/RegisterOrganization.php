<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\OrganizationRole;
use App\Filament\Support\OrganizationNameInput;
use App\Filament\Support\OrganizationTimezoneSelect;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class RegisterOrganization extends RegisterTenant
{
    #[Locked]
    public ?string $returnUrl = null;

    public static function getLabel(): string
    {
        return 'New Organization';
    }

    public function mount(): void
    {
        parent::mount();

        $this->returnUrl = $this->returnUrlFor(auth()->user());
    }

    /**
     * Where Cancel goes: the dashboard of the organization the user came from, rebuilt from one of their own
     * organizations (never the raw previous URL), or else their default organization.
     */
    private function returnUrlFor(User $user): ?string
    {
        $previousPath = trim((string) parse_url(url()->previous(), PHP_URL_PATH), '/');
        $panelPath = trim(Filament::getCurrentOrDefaultPanel()->getPath(), '/');
        $segments = explode('/', $previousPath);
        $cameFrom = null;

        if ($panelPath !== '' && array_shift($segments) === $panelPath) {
            $cameFrom = $user->organizations()->where('slug', $segments[0] ?? '')->first();
        }

        $organization = $cameFrom ?? Filament::getUserDefaultTenant($user);

        return $organization ? Filament::getUrl($organization) : null;
    }

    public function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->color('gray')
            ->url($this->returnUrl)
            ->visible(fn (): bool => $this->returnUrl !== null);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getRegisterFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                OrganizationNameInput::make(),
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
