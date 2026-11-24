<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Account\ChangePassword;
use App\Actions\Account\DeleteUserAccount;
use App\Support\Auth\EmailChanges;
use App\Support\Auth\UniqueUserEmail;
use App\Support\NotificationText;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The panel profile page: name, email (SEC-56 pending change), task reminder mails, password, the second factor and account deletion (SEC-27); an unverified user only gets the profile, notification and pending-change sections (FEAT-151).
 */
class EditProfile extends BaseEditProfile
{
    protected ?string $requestedEmail = null;

    /**
     * Unverified users may open the profile to fix a mistyped address (FEAT-151); every other panel page still requires a verified email.
     */
    public static function isEmailVerificationRequired(Panel $panel): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->description('Update your name and email address')
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
            Section::make('Email notifications')
                ->description('Choose which emails you get from the CRM')
                ->schema([
                    Toggle::make('task_reminder_mails')
                        ->label('Task reminder emails')
                        ->helperText('Due-tomorrow and overdue reminders, and the daily task digest, in every organization you belong to. Notifications in the app are not affected.'),
                ]),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email address')
            ->email()
            ->validationAttribute('email')
            ->required()
            ->maxLength(255)
            ->rule(new UniqueUserEmail($this->getUser()->getKey()))
            ->live(debounce: 500);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(function (Get $get): bool {
                $email = $get('email');

                return ! is_string($email) || Str::lower(trim($email)) !== Str::lower((string) $this->getUser()->email);
            });
    }

    /**
     * Changes the name, and for a new address only asks for the SEC-56 pending change: `users.email` is never written.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $newEmail = Str::lower(trim((string) $data['email']));

        if ($newEmail === Str::lower((string) $record->getAttribute('email'))) {
            $record->forceFill(['name' => $data['name'], 'email' => $newEmail, 'task_reminder_mails' => (bool) $data['task_reminder_mails']])->save();

            return $record;
        }

        $refusal = EmailChanges::request($record, $newEmail);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['data.email' => $refusal]);
        }

        $record->forceFill(['name' => $data['name'], 'task_reminder_mails' => (bool) $data['task_reminder_mails']])->save();

        $this->requestedEmail = $newEmail;

        return $record;
    }

    /**
     * The typed current password never outlives one save attempt, whether it succeeded, failed validation or was refused.
     */
    public function save(): void
    {
        try {
            parent::save();
        } finally {
            $this->data['currentPassword'] = null;
        }
    }

    protected function afterSave(): void
    {
        $this->data['email'] = $this->getUser()->email;
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->requestedEmail === null) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->success()
            ->title(NotificationText::plain('Check '.$this->requestedEmail.' for a link to confirm your new email address.'));
    }

    public function content(Schema $schema): Schema
    {
        $isVerified = $this->getUser()->hasVerifiedEmail();

        return $schema->components([
            $this->getFormContentComponent(),
            $this->getPendingEmailChangeComponent(),
            ...($isVerified ? [
                $this->getPasswordComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getDeleteAccountComponent(),
            ] : []),
        ]);
    }

    protected function getPasswordComponent(): Component
    {
        return Section::make('Password')
            ->key('passwordSection')
            ->description('Ensure your account is using a long, random password to stay secure')
            ->schema([
                TextEntry::make('passwordNotice')
                    ->hiddenLabel()
                    ->state('Changing your password signs you out on your other devices.'),
            ])
            ->footerActions([$this->getChangePasswordAction()]);
    }

    /**
     * Asks for the current password, is throttled per IP and per user before validating, and changes only the
     * signed-in user's own password through the shared `ChangePassword` action.
     */
    protected function getChangePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->modalHeading('Change password')
            ->modalSubmitActionLabel('Update password')
            ->rateLimit(5)
            ->beforeFormValidated(function (Action $action): void {
                $key = 'profile-password-change:'.$this->getUser()->getKey();

                if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
                    $action->sendRateLimitedNotification(new TooManyRequestsException(
                        static::class,
                        'changePassword',
                        request()->ip(),
                        RateLimiter::availableIn($key),
                    ));

                    $action->halt();
                }

                RateLimiter::hit($key);
            })
            ->schema([
                TextInput::make('currentPassword')
                    ->label('Current password')
                    ->validationAttribute('current password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
                TextInput::make('password')
                    ->label('New password')
                    ->validationAttribute('new password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(Password::default())
                    ->showAllValidationMessages()
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label('Confirm new password')
                    ->validationAttribute('new password confirmation')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (array $data): void {
                app(ChangePassword::class)->handle($this->getUser(), $data['password']);

                Notification::make()->success()->title('Password updated. You are signed out on your other devices.')->send();
            });
    }

    protected function getDeleteAccountComponent(): Component
    {
        return Section::make('Delete account')
            ->key('deleteAccountSection')
            ->description('Delete your account and your personal workspace')
            ->schema([
                TextEntry::make('deleteAccountNotice')
                    ->hiddenLabel()
                    ->state('This cannot be undone. If you own an organization other people work in, transfer it or delete it first.'),
            ])
            ->footerActions([$this->getDeleteAccountAction()]);
    }

    /**
     * Asks for the current password, is throttled per IP and per user before validating, and deletes only the
     * signed-in user's own account through the shared `DeleteUserAccount` action (SEC-27).
     */
    protected function getDeleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->label('Delete account')
            ->color('danger')
            ->modalHeading('Are you sure you want to delete your account?')
            ->modalDescription('Your personal workspace and any organization where you are the only member are deleted with all their data. What you created in organizations other people still use stays there. Enter your password to confirm.')
            ->modalSubmitActionLabel('Delete account')
            ->rateLimit(5)
            ->beforeFormValidated(function (Action $action): void {
                $key = 'profile-account-delete:'.$this->getUser()->getKey();

                if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
                    $action->sendRateLimitedNotification(new TooManyRequestsException(
                        static::class,
                        'deleteAccount',
                        request()->ip(),
                        RateLimiter::availableIn($key),
                    ));

                    $action->halt();
                }

                RateLimiter::hit($key);
            })
            ->schema([
                TextInput::make('password')
                    ->label('Password')
                    ->validationAttribute('password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
            ])
            ->action(function (): void {
                $user = $this->getUser();

                $refusal = app(DeleteUserAccount::class)->handle($user, function (): void {
                    Filament::auth()->logout();
                    session()->invalidate();
                    session()->regenerateToken();
                });

                if ($refusal !== null) {
                    Notification::make()->danger()->title(NotificationText::plain($refusal))->persistent()->send();

                    return;
                }

                $this->redirect(route('home'));
            });
    }

    protected function getPendingEmailChangeComponent(): Component
    {
        return Section::make('Email change pending')
            ->key('pendingEmailChange')
            ->schema([
                TextEntry::make('pendingEmailNotice')
                    ->hiddenLabel()
                    ->state(fn (): string => 'We sent a link to '.$this->getUser()->pending_email.' to confirm your new email address. Your email stays '.$this->getUser()->email.' until you open it.'),
            ])
            ->footerActions([
                Action::make('resendEmailChangeLink')
                    ->label('Send the link again')
                    ->link()
                    ->action(function (): void {
                        $user = $this->getUser();
                        $refusal = EmailChanges::resend($user);

                        if ($refusal !== null) {
                            Notification::make()->danger()->title(NotificationText::plain($refusal))->send();

                            return;
                        }

                        if ($user->pending_email !== null) {
                            Notification::make()->success()->title(NotificationText::plain('A new link has been sent to '.$user->pending_email.'.'))->send();
                        }
                    }),
                Action::make('cancelEmailChange')
                    ->label('Cancel the change')
                    ->link()
                    ->color('gray')
                    ->action(function (): void {
                        $user = $this->getUser();

                        if ($user->pending_email === null) {
                            return;
                        }

                        EmailChanges::cancel($user);

                        Notification::make()->success()->title(NotificationText::plain('The email change was cancelled. Your email stays '.$user->email.'.'))->send();
                    }),
            ])
            ->visible(fn (): bool => $this->getUser()->fresh()?->pending_email !== null);
    }
}
