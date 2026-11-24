<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\VerificationMails;
use App\Support\NotificationText;
use Filament\Actions\Action;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt as BaseEmailVerificationPrompt;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\HtmlString;

/**
 * @property-read Action $changeEmailAction
 */
class EmailVerificationPrompt extends BaseEmailVerificationPrompt
{
    /**
     * The vendor lines plus a link to the panel profile, where a mistyped address is fixed (FEAT-151).
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Text::make(__('filament-panels::auth/pages/email-verification/email-verification-prompt.messages.notification_sent', [
                    'email' => filament()->auth()->user()->getEmailForVerification(),
                ])),
                Text::make(new HtmlString(
                    __('filament-panels::auth/pages/email-verification/email-verification-prompt.messages.notification_not_received').
                    ' '.
                    $this->resendNotificationAction->toHtml(),
                )),
                Text::make(new HtmlString(e('Wrong address?').' '.$this->changeEmailAction->toHtml())),
            ]);
    }

    public function changeEmailAction(): Action
    {
        return Action::make('changeEmail')
            ->link()
            ->label('Change it.')
            ->size('sm')
            ->url(fn (): ?string => Filament::getProfileUrl());
    }

    /**
     * Sends the mail through the per-user cap (SEC-24). A refused resend shows the reason and stops the action, so
     * the base "We've resent the email." notification is not shown.
     *
     * @throws Halt
     */
    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        $refusal = $user instanceof User ? VerificationMails::send($user) : null;

        if ($refusal === null) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(NotificationText::plain($refusal))
            ->send();

        throw new Halt;
    }
}
