<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

trait RespectsTaskReminderMailSetting
{
    /**
     * Whether a task reminder mail may go to this notifiable: a verified user who did not switch them off.
     */
    protected function mailsTaskRemindersTo(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->wantsTaskReminderMails();
    }

    /**
     * Adds the one-click unsubscribe headers (RFC 8058) and a footer line with the unsubscribe and settings links.
     * Runs in the queue worker, so the URLs come from APP_URL, never from a request's Host header.
     */
    protected function withUnsubscribeLink(MailMessage $mail, object $notifiable): MailMessage
    {
        if (! $notifiable instanceof User) {
            return $mail;
        }

        $unsubscribeUrl = URL::signedRoute('task-mails.unsubscribe', ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->email)]);

        return $mail
            ->withSymfonyMessage(function (Email $message) use ($unsubscribeUrl): void {
                $message->getHeaders()
                    ->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>')
                    ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            })
            ->line(new HtmlString('You get this email because task reminder emails are on for your account. [Unsubscribe]('.$unsubscribeUrl.') or [change your email settings]('.route('notification-settings.edit').').'));
    }

    /**
     * Re-checked when the queued mail is delivered, so a user who opts out after it was queued gets nothing.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'mail' || $this->mailsTaskRemindersTo($notifiable);
    }
}
