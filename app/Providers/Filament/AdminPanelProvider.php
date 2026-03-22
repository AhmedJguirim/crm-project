<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\DashboardTasksWidget;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Filament\Widgets\RevenueOverviewWidget;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterOrganization::class)
            ->tenantProfile(EditOrganization::class)
            ->databaseNotifications()
            ->tenantMenuItems([
                // Show the current organization as a disabled item at the top of the switcher
                Action::make('currentOrganization')
                    ->label(fn (): string => Filament::getTenant()?->name ?? '')
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
                            ->placeholder(Filament::getTenant()?->name)
                            ->required()
                            ->rules([
                                fn () => function (string $attribute, $value, $fail) {
                                    if ($value !== Filament::getTenant()?->name) {
                                        $fail('The organization name does not match.');
                                    }
                                },
                            ])
                            ->helperText('This action is irreversible. You will lose access to this organization.'),
                    ])
                    ->modalHeading('Leave Organization')
                    ->modalDescription(fn (): string => 'Are you sure you want to leave "'.(Filament::getTenant()?->name ?? '').'"? You will lose access to all its resources.')
                    ->modalSubmitActionLabel('Leave')
                    ->action(function () {
                        /** @var Organization $organization */
                        $organization = Filament::getTenant();
                        /** @var User $user */
                        $user = auth()->user();

                        // Detach the user from the organization
                        $organization->members()->detach($user->id);

                        // Delete invite if user was invited
                        $invite = OrganizationInvite::where('email', $user->email)->where('organization_id', $organization->id)->first();
                        if ($invite) {
                            $invite->delete();
                        }

                        Notification::make()
                            ->success()
                            ->title('You have left '.$organization->name)
                            ->send();

                        // Redirect to the user's personal organization
                        $personal = $user->personalOrganization();
                        redirect()->to(Filament::getUrl($personal));
                    })
                    ->visible(function (): bool {
                        /** @var Organization|null $organization */
                        $organization = Filament::getTenant();

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
            ])
            ->font('Inter')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('CRM')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                DealsOverviewWidget::class,
                RevenueOverviewWidget::class,
                ContactsOverviewWidget::class,
                DashboardTasksWidget::class,
                RecentActivitiesWidget::class,
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
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
