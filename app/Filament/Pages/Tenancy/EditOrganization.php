<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\Organization\DeleteOrganization;
use App\Filament\Support\OrganizationCurrencySelect;
use App\Filament\Support\OrganizationNameInput;
use App\Filament\Support\OrganizationTimezoneSelect;
use App\Models\Organization;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

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

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteOrganization')
                ->label('Delete organization')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->authorize('delete', CurrentOrganization::get())
                ->modalHeading('Delete organization')
                ->modalDescription(fn (): string => '"'.(CurrentOrganization::find()?->name ?? '').'" and all of its contacts, companies, deals, tasks, activities, invoices and segments will be deleted for every member. It is kept for 30 days, then removed for good.')
                ->modalSubmitActionLabel('Delete organization')
                ->schema(fn (): array => [
                    TextInput::make('confirmation')
                        ->label('Type the organization name to confirm')
                        ->placeholder(CurrentOrganization::find()?->name)
                        ->required()
                        ->rules([
                            fn () => function (string $attribute, mixed $value, $fail): void {
                                if ($value !== CurrentOrganization::find()?->name) {
                                    $fail('The organization name does not match.');
                                }
                            },
                        ]),
                ])
                ->action(function (): void {
                    /** @var Organization|null $organization */
                    $organization = CurrentOrganization::find();
                    /** @var User $user */
                    $user = auth()->user();

                    if ($organization === null) {
                        return;
                    }

                    $refusal = app(DeleteOrganization::class)->handle($user, $organization);

                    if ($refusal !== null) {
                        Notification::make()
                            ->danger()
                            ->title(NotificationText::plain($refusal))
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(NotificationText::plain($organization->name.' was deleted'))
                        ->body(NotificationText::plain('It will be removed for good in '.Organization::DELETION_GRACE_DAYS.' days.'))
                        ->send();

                    $this->redirect(Filament::getUrl($user->personalOrganization()));
                })
                ->visible(fn (): bool => CurrentOrganization::find()?->personal_team === false),
        ];
    }
}
