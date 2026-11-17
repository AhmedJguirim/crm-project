<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\VerificationMails;
use App\Support\NotificationText;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt as BaseEmailVerificationPrompt;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class EmailVerificationPrompt extends BaseEmailVerificationPrompt
{
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
