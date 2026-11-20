<?php

namespace App\Providers\Filament;

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Security\LoggedAppAuthentication;
use App\Support\Security\SecurityLog;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\UserFiles;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->registration(Register::class)
            ->emailVerification(EmailVerificationPrompt::class)
            ->profile(EditProfile::class)
            ->multiFactorAuthentication([LoggedAppAuthentication::make()->recoverable()])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.auth.legal-links'))
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterOrganization::class)
            ->tenantProfile(EditOrganization::class)
            ->databaseNotifications()
            ->tenantMenuItems([
                // Show the current organization as a disabled item at the top of the switcher
                Action::make('currentOrganization')
                    ->label(fn (): string => CurrentOrganization::find()?->name ?? '')
                    ->icon('heroicon-m-check-circle')
                    ->color('primary')
                    ->disabled()
                    ->sort(-1),
            ])
            ->userMenuItems([
                // "Leave Organization" button — hidden on personal orgs
                Action::make('leaveOrganization')
                    ->label('Leave Organization')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->schema(fn (): array => [
                        TextInput::make('confirmation')
                            ->label('Type the organization name to confirm')
                            ->placeholder(CurrentOrganization::find()?->name)
                            ->required()
                            ->rules([
                                fn () => function (string $attribute, $value, $fail) {
                                    if ($value !== CurrentOrganization::find()?->name) {
                                        $fail('The organization name does not match.');
                                    }
                                },
                            ])
                            ->helperText('This action is irreversible. You will lose access to this organization.'),
                    ])
                    ->modalHeading('Leave Organization')
                    ->modalDescription(fn (): string => 'Are you sure you want to leave "'.(CurrentOrganization::find()?->name ?? '').'"? You will lose access to all its resources.')
                    ->modalSubmitActionLabel('Leave')
                    ->action(function () {
                        /** @var Organization $organization */
                        $organization = CurrentOrganization::find();
                        /** @var User $user */
                        $user = auth()->user();

                        $outcome = DB::transaction(function () use ($organization, $user): string|OrganizationRole {
                            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

                            if ($locked === null) {
                                return 'This organization was deleted.';
                            }

                            $current = DB::table('organization_user')
                                ->where('organization_id', $locked->getKey())
                                ->where('user_id', $user->id)
                                ->value('role');

                            if ($current === OrganizationRole::Owner->value) {
                                return 'You are now the owner of this organization. Transfer ownership before leaving.';
                            }

                            if ($current === null) {
                                return 'You are no longer a member of this organization.';
                            }

                            $locked->members()->detach($user->id);

                            $locked->deleteInvitesFor($user);

                            return OrganizationRole::from($current);
                        });

                        if (is_string($outcome)) {
                            Notification::make()
                                ->danger()
                                ->title('You have not left')
                                ->body(NotificationText::plain($outcome))
                                ->send();

                            return;
                        }

                        SecurityLog::info('organization.member_left', $user, ['role' => $outcome->value]);

                        UserFiles::deleteForMember($user->id, $organization->id);

                        Notification::make()
                            ->success()
                            ->title(NotificationText::plain('You have left '.$organization->name))
                            ->send();

                        // Redirect to the user's personal organization
                        $personal = $user->personalOrganization();
                        redirect()->to(Filament::getUrl($personal));
                    })
                    ->visible(function (): bool {
                        /** @var Organization|null $organization */
                        $organization = CurrentOrganization::find();

                        if (! $organization) {
                            return false;
                        }

                        // Hide on personal organizations — user can't leave their own
                        if ($organization->personal_team) {
                            return false;
                        }

                        // Hide if the user is the owner — must transfer ownership first
                        /** @var User $user */
                        $user = auth()->user();

                        return ! $organization->isOwner($user);
                    }),
            ])
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
                'success' => Color::Green,
                'warning' => Color::Yellow,
                'danger' => Color::Red,
                'info' => Color::Sky,
                'orange' => Color::Orange,
            ])
            ->font('Inter')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('CRM')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
