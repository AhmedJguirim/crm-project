<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the new address of an account to confirm it (SEC-56); queued after commit and encrypted in the queue payload
 * because it holds a signed link.
 */
final class ConfirmNewEmailAddress extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->line('Open the link below to use this address for your account. Until you do, the account keeps its current email address.')
            ->action('Confirm email address', $this->url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, ignore this email: nothing changes.');
    }
}
