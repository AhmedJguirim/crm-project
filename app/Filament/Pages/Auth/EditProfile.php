<?php

namespace App\Filament\Pages\Auth;

use App\Support\Auth\EmailChanges;
use App\Support\Auth\UniqueUserEmail;
use App\Support\NotificationText;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The panel profile page: name, email (SEC-56 pending change) and the second factor. Password, task mails and account
 * deletion are still on `/settings/*` until FEAT-149/150.
 */
class EditProfile extends BaseEditProfile
{
    protected ?string $requestedEmail = null;

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
            $record->forceFill(['name' => $data['name'], 'email' => $newEmail])->save();

            return $record;
        }

        $refusal = EmailChanges::request($record, $newEmail);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['data.email' => $refusal]);
        }

        $record->forceFill(['name' => $data['name']])->save();

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
        return $schema->components([
            $this->getFormContentComponent(),
            $this->getPendingEmailChangeComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
        ]);
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
